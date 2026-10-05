<?php
/**
 * lib/content.php — Layer 3A: the ONE owner of every content contract.
 *
 * docs/v2/LAYER-3-CONTRACT.md §2.1 ("One owner") as ratified in #1167 (P-1..P-27). Every
 * content-bearing write calls pp_content_sanitize() through the shared validation engine
 * (pp_validate_composition_errors(), lib/admin.php); there is no second sanitizer. The
 * render side (§2.3, Sprint 6 T3b) calls the SAME function and reads its `html`.
 *
 * ── THE MECHANISM: ONE PARSE, TWO VIEWS (M-19, adopted by prototype) ──────────────────
 *
 * The contract's §2.1 pipeline (style lift → wp_kses() with a PP table → post-kses value
 * gates → verify) carried an advisory, M-19: replace steps 2-4 with ONE
 * WP_HTML_Processor::create_full_parser() walk inside the per-sink wrapper, adopted if
 * T-9 and T-17 pass. It was prototyped (rule 14.3) against WordPress 7.0 and reproduced
 * every measurement the contract cites, so this file is that walk. Consequences:
 *
 *   - wp_kses() is never called. The §2.4 `pre_kses` hazard and the step-2/step-4
 *     style-slot markers do not exist. The M-4 table survives as DATA: the allowlist
 *     the walk enforces (pp_content_html_table()), pinned against core (T-1).
 *   - The tree walk is what a browser builds (namespaces, integration points, implied
 *     end tags). It is the authority for E5, E10, Δ1 and every namespace rule.
 *   - The tree builder IGNORES some tokens a browser still acts on. Measured: an
 *     in-body `<body onload=x>` produces no token, yet a browser merges `onload` onto
 *     the real <body>; `<select><option><img>` loses the img while Chromium's
 *     customizable-select parser keeps it. So a LEXICAL pass (WP_HTML_Tag_Processor,
 *     which reports every tag token) judges the namespace-independent rules too.
 *     THE UNION RULE: a construct must pass both views. A disagreement can only add a
 *     Loss, never remove one.
 *
 *     raw bytes ──┬── lexical scan (every tag token) ── E1 E2 E3 E4 E6 E8 E9 E7/Δ3 ──┐
 *                 │                                                                 ├─ losses
 *                 └── wrapper + full parser walk ── table, namespaces, Δ1/E5, E6,  ──┤
 *                       E10 containment, E11, M-8 depth, P-16 bail, Δ5, P-11,       │
 *                       ids and references ── E12 against the page (finish) ────────┤
 *                                                └── admitted tokens ── html (T3b) ──┘
 *
 * ── REFUSE, NEVER COERCE (§2.2) ─────────────────────────────────────────────────────
 *
 * The predicate never edits the author's bytes for the write path. It returns facts
 * (`losses`); the engine refuses the whole write on any Loss with
 * `content_construct_excluded`, and accepted bytes are stored exactly as sent. The
 * `html` it returns is the render-side view (admitted tokens re-serialized, Δ4's
 * `noopener` added, whole-prop losses rendered empty) and nothing on the write path
 * stores it.
 *
 * NO try/catch anywhere in this file (§2.4). Nothing here enters kses, so nothing can
 * leave a kses filter unhooked; a non-string is refused as a Loss by returning.
 */

/** Loss clauses that concern the whole prop rather than one construct in it (§2.3). */
const PP_CONTENT_WHOLE_PROP_CLAUSES = ['E10', 'P-16', 'M-8'];

/** P-10's raster `data:` admission rides pp_esc_image_src()'s existing cap (one constant). */
const PP_CONTENT_DATA_IMAGE_MAX_BYTES = PP_IMAGE_DATA_URI_MAX_BYTES;

/**
 * M-8, measured (2026-10-05, ruling on T3a 7A question 2): the largest power of two whose
 * WORST-CASE judge time stays under about one second on the T-17 rig (PHP 8.3, WordPress
 * 7.0's HTML API, best of two runs, one rich prop):
 *
 *     bytes     <p> repeated  <b>x</b>  <i></i>  nested <div>  styled <span>  links   entities
 *     32 KiB         —         0.19 s     —        0.10 s        0.05 s       0.05 s   0.01 s
 *     64 KiB         —         0.38 s     —        0.25 s        0.09 s       0.10 s   0.03 s
 *     128 KiB      1.01 s      0.84 s   0.70 s     0.47 s        0.19 s       0.19 s   0.05 s
 *     256 KiB        —         1.62 s     —        0.56 s        0.40 s       0.39 s   0.11 s
 *
 * The densest shape (an implied-close `<p>` per 3 bytes) reaches 1.01 s at 128 KiB, over the
 * line, so the cap is 64 KiB (worst case about 0.5 s), which is also the contract's M-8
 * figure. A raster `data:` image (P-10) is therefore bounded by this cap before
 * pp_esc_image_src()'s 1 MB one.
 */
const PP_CONTENT_PROP_MAX_BYTES = 65536;

/**
 * M-8 nesting (2026-10-05 rulings, cycles 6 and 7): the deepest element nesting a content
 * prop may carry, read from the PARSER'S OWN stack depth in the tree walk (never a lexical
 * model of it: a model under-counts what the parser keeps open, such as a self-closed
 * custom element, and over-counts what it ignores, such as a closer behind a table cell).
 * Core's WP_HTML_Processor is quadratic in nesting depth (a `<b>` nested 9,285 deep in one
 * 64 KiB value took 3.7 s; a 1 MiB write of such values ~60 s); the walk stops at the first
 * element past the cap, so it never reaches that region (the 9,285-deep value is refused in
 * about 20 ms). Measured with the cap at 256 on the T-17 rig, each value filled to 64 KiB
 * with back-to-back nests as deep as the cap admits, best of two runs:
 *
 *     shape                              one 64 KiB value   16 values (1 MiB)
 *     <b><i><u><s>                            0.41 s              ~6.5 s
 *     <b>                                     0.39 s              ~6.2 s
 *     <p><table><tr><td><b><i></p>            0.32 s              ~5.1 s
 *     <em>                                    0.30 s              ~4.9 s
 *     <a-/> (self-closed custom element)      0.20 s              ~3.2 s
 *     <div>                                   0.17 s              ~2.8 s
 *     <span><div></span>                      0.16 s              ~2.6 s
 *     <span>                                  0.15 s              ~2.4 s
 *
 * So the worst admitted write stays inside the ~10 s design target. A prop deeper than this
 * is refused whole and named (clause M-8), never truncated.
 */
const PP_CONTENT_MAX_DEPTH = 256;

/**
 * M-8 per WRITE: the changed content one write may ask the gate to judge. Bytes bound the
 * parse cost (the worst shape is about 8 s per MiB); the value count bounds the per-value
 * cost (one wrapper parse, about 0.44 ms each, which a 10,000-cell table would otherwise pay
 * at about 4.4 s). Together the worst admitted write is about 10 s, inside PHP's 30 s web
 * limit, so an over-large write gets a named refusal instead of a timeout. Unchanged bands
 * (§2.6) are not judged and do not count here; they are walked for their cross-band facts
 * with what the write leaves of the same byte budget (pp_content_composition_index()).
 */
const PP_CONTENT_WRITE_MAX_BYTES = 1048576;
const PP_CONTENT_WRITE_MAX_VALUES = 4096;

/**
 * The M-8 refusals for one changed band, charging its content to the write's budget.
 *
 * @param  array  $item    The band.
 * @param  string $name    Its component, for messages.
 * @param  array  $budget  The write's running totals (bytes, values), updated in place.
 * @return list<array{prop:string, message:string}>
 */
function pp_content_band_size_errors(array $item, string $name, array &$budget): array {
    $errors = [];
    $values = pp_content_band_values($item);
    foreach ($values as [$label, , , $value]) {
        if (strlen($value) > PP_CONTENT_PROP_MAX_BYTES) {
            $errors[] = ['prop' => $label, 'message' => sprintf(
                'Component "%s" prop %s is %s bytes; a content prop may be at most %s bytes (LAYER-3-CONTRACT.md M-8). '
                . 'Nothing was stored; content is refused whole, never truncated, so split it across bands or items.',
                $name, $label, number_format(strlen($value)), number_format(PP_CONTENT_PROP_MAX_BYTES)
            )];
        }
    }
    if ($errors !== []) {
        return $errors;
    }
    $budget['bytes'] += array_sum(array_map(static fn ($v) => strlen($v[3]), $values));
    $budget['values'] += count($values);
    if ($budget['bytes'] > PP_CONTENT_WRITE_MAX_BYTES || $budget['values'] > PP_CONTENT_WRITE_MAX_VALUES) {
        $errors[] = ['prop' => '(this write)', 'message' => sprintf(
            'Component "%s": this write carries %s bytes in %s content values to check, counting up to this band; '
            . 'one write may carry at most %s bytes and %s values of changed content (LAYER-3-CONTRACT.md M-8). '
            . 'Nothing was stored; content is refused whole, never truncated, so split the change across several writes.',
            $name, number_format($budget['bytes']), number_format($budget['values']),
            number_format(PP_CONTENT_WRITE_MAX_BYTES), number_format(PP_CONTENT_WRITE_MAX_VALUES)
        )];
    }
    return $errors;
}

/**
 * The content contract of every content-bearing prop, by component (§1.1 + P-2 B+).
 *
 * Paths: `prop` for a scalar prop, `prop[].field` for a field of a list entry,
 * `prop[][]` for a list of lists of strings (table rows). Sinks:
 *
 *   rich       core's `post` breadth plus the Δ rows, parsed inside a <div> chain
 *   rich_cell  the same contract parsed inside table > tbody > tr > td
 *   inline     P-2 B+: a[href|title] strong em br span[class|style] sup sub small mark code,
 *              parsed inside the template's <p>
 *   heading    the SAME inline set (P-2 B+ "the same inline set in PLAIN titles and
 *              headings"), parsed inside an <h2>. The props are the ones the templates
 *              render as a band's or an item's heading (h1-h3) plus each band's
 *              `subheading` (the band's sub-heading line; named a heading by the schema)
 *
 * Every prop NOT listed is PLAIN (§3.3): escaped, never parsed, never a Loss. That
 * includes every label, eyebrow, button text, URL, alt text, caption and all chrome
 * text, which P-2 keeps PLAIN.
 *
 * @return array<string, array<string, string>>
 */
function pp_content_prop_contracts(): array {
    $headings = ['title' => 'heading', 'title_accent' => 'heading'];
    return [
        'section'      => $headings + [
            'subheading'    => 'heading',
            'panel_heading' => 'heading',
            'body'          => 'rich',
        ],
        'hero'         => $headings + ['subheading' => 'heading', 'proof' => 'rich'],
        'cta'          => $headings + ['body' => 'inline'],
        'grid'         => $headings + [
            'subheading'     => 'heading',
            'items[].title'  => 'heading',
            'items[].text'   => 'inline',
        ],
        'testimonials' => $headings + ['subheading' => 'heading', 'items[].quote' => 'inline'],
        'faq'          => $headings + ['items[].answer' => 'rich'],
        'stats'        => $headings,
        'embed'        => ['title' => 'heading', 'content' => 'rich'],
        'logos'        => ['title' => 'heading'],
        'table'        => ['title' => 'heading', 'rows[][]' => 'rich_cell'],
    ];
}

/**
 * Every content value a band carries, as [path-for-messages, contract-path, sink, value].
 * Non-string values are skipped: the schema rules already refuse them with their own
 * codes, and reporting them twice would only add noise (§2.1's guard still holds inside
 * pp_content_sanitize() for any direct caller).
 *
 * @return list<array{0:string,1:string,2:string,3:string}>
 */
function pp_content_band_values(array $item): array {
    $name = (isset($item['component']) && is_string($item['component'])) ? $item['component'] : '';
    $contracts = pp_content_prop_contracts()[$name] ?? [];
    $props = (isset($item['props']) && is_array($item['props'])) ? $item['props'] : [];
    $out = [];
    foreach ($contracts as $path => $sink) {
        if (preg_match('/^([a-z_]+)\[\]\[\]$/', $path, $m)) {
            $rows = $props[$m[1]] ?? null;
            if (!is_array($rows)) {
                continue;
            }
            foreach ($rows as $r => $row) {
                if (!is_array($row)) {
                    continue;
                }
                foreach ($row as $c => $cell) {
                    if (is_string($cell) && $cell !== '') {
                        $out[] = [sprintf('%s row %s cell %s', $m[1], $r, $c), $path, $sink, $cell];
                    }
                }
            }
        } elseif (preg_match('/^([a-z_]+)\[\]\.([a-z_]+)$/', $path, $m)) {
            $entries = $props[$m[1]] ?? null;
            if (!is_array($entries)) {
                continue;
            }
            foreach ($entries as $k => $entry) {
                if (is_array($entry) && isset($entry[$m[2]]) && is_string($entry[$m[2]]) && $entry[$m[2]] !== '') {
                    $out[] = [sprintf('%s item %s field "%s"', $m[1], $k, $m[2]), $path, $sink, $entry[$m[2]]];
                }
            }
        } elseif (isset($props[$path]) && is_string($props[$path]) && $props[$path] !== '') {
            $out[] = [sprintf('"%s"', $path), $path, $sink, $props[$path]];
        }
    }
    return $out;
}

// ── THE ADMISSION TABLE (M-4, P-17, P-18, Δ1-Δ5, minus §4) ─────────────────────────────

/** Loads one of the derived data tables shipped beside this file. */
function _pp_content_data_table(string $file): array {
    static $cache = [];
    if (!isset($cache[$file])) {
        $path = __DIR__ . '/content-tables/' . $file;
        $raw = is_readable($path) ? file_get_contents($path) : false;
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        $cache[$file] = is_array($decoded) ? $decoded : [];
    }
    return $cache[$file];
}

/**
 * The §4 hard exclusions, by element name (HTML namespace), with their clause.
 * E3 + E4. The base and every Δ row lose to these (§4 "Precedence").
 */
function pp_content_excluded_elements(): array {
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $e3 = ['iframe', 'frame', 'frameset', 'object', 'embed', 'applet', 'portal', 'fencedframe'];
    $e4 = ['script', 'style', 'noscript', 'template', 'base', 'meta', 'link', 'title', 'html',
        'head', 'body', 'slot', 'xmp', 'noembed', 'noframes', 'plaintext', 'listing'];
    // Δ5, DESCOPED BY THE OWNER 2026-10-05: `form` and its controls are refused until the
    // Layer-3 forms contract (the scheduled destination of P-5/P-24) admits them. `button`
    // stays: it carries the invoker commands (popovertarget, commandfor) and, with no form
    // and `form=` refused (E9), submits nothing. `label`, `meter` and `progress` stay too:
    // they label and show values, and own no form data.
    $d5 = ['form', 'input', 'select', 'option', 'optgroup', 'selectedcontent', 'datalist', 'textarea',
        'output', 'fieldset', 'legend'];
    return $cache = array_fill_keys($e3, 'E3') + array_fill_keys($e4, 'E4') + array_fill_keys($d5, 'D5');
}

/**
 * E5: SVG and MathML active / cross-namespace elements (lowercased local names).
 * `mpath` is listed with the animation family it exists to serve (it is only ever the
 * child of animateMotion), so admitting it alone would be the I19 shape.
 */
function pp_content_excluded_foreign_elements(): array {
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    return $cache = array_fill_keys([
        'animate', 'animatecolor', 'animatemotion', 'animatetransform', 'set', 'discard', 'mpath',
        'annotation-xml', 'mglyph', 'malignmark', 'maction', 'foreignobject', 'image', 'feimage',
        'script', 'style',
    ], 'E5');
}

/** E9: submission and navigation redirectors, refused on every element. */
function pp_content_excluded_attributes(): array {
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    return $cache = array_fill_keys(['formaction', 'formtarget', 'formmethod', 'formenctype', 'ping',
        'http-equiv', 'form'], 'E9') + ['srcdoc' => 'E3'];
}

/**
 * The HTML global attributes (P-17: "the full HTML and ARIA 1.2 global set").
 *
 * core `post` ships 19 of them; the living standard's global set is admitted on top,
 * minus §4, and the four P-17 asked to be argued one by one:
 *
 *   autofocus        ADMITTED. It moves focus on load, which executes nothing, fetches
 *                    nothing and forges nothing; the scroll it can cause is an author-owned
 *                    outcome of the same class as `position: fixed` (§4 "not on this
 *                    list"). Its old XSS role needed an event handler, which E1 refuses.
 *   contenteditable  ADMITTED. It lets a visitor edit the page in their own tab; nothing is
 *                    stored or sent, no script runs. Author-owned outcome, like `popover`.
 *   nonce            REFUSED (clause P-17). Its only effect is on <script>, <style> and
 *                    <link>, which E4 already refuses, so on every element content may carry
 *                    it does nothing — an attribute that validates green and does nothing is
 *                    the I19 shape, and refusing it names that instead of hiding it.
 *   is               ADMITTED, value gated to a valid custom element name that does not
 *                    shadow an HTML/SVG/MathML element, and disclosed: it upgrades a built-in
 *                    element only when a page script defines that name, which is the plugin
 *                    boundary P-9 states and P-23 applies to custom elements.
 */
function pp_content_html_global_attributes(): array {
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $spec = ['accesskey', 'autocapitalize', 'autocorrect', 'autofocus', 'class', 'contenteditable',
        'dir', 'draggable', 'enterkeyhint', 'hidden', 'id', 'inert', 'inputmode', 'is', 'itemid',
        'itemprop', 'itemref', 'itemscope', 'itemtype', 'lang', 'popover', 'slot', 'spellcheck',
        'style', 'tabindex', 'title', 'translate', 'writingsuggestions', 'role', 'xml:lang'];
    return $cache = array_fill_keys(array_merge($spec, pp_content_aria_attributes()), true);
}

/** WAI-ARIA 1.2 states and properties (§6.6 of the ARIA 1.2 recommendation), complete. */
function pp_content_aria_attributes(): array {
    return ['aria-activedescendant', 'aria-atomic', 'aria-autocomplete', 'aria-busy', 'aria-checked',
        'aria-colcount', 'aria-colindex', 'aria-colspan', 'aria-controls', 'aria-current',
        'aria-describedby', 'aria-details', 'aria-disabled', 'aria-dropeffect', 'aria-errormessage',
        'aria-expanded', 'aria-flowto', 'aria-grabbed', 'aria-haspopup', 'aria-hidden', 'aria-invalid',
        'aria-keyshortcuts', 'aria-label', 'aria-labelledby', 'aria-level', 'aria-live', 'aria-modal',
        'aria-multiline', 'aria-multiselectable', 'aria-orientation', 'aria-owns', 'aria-placeholder',
        'aria-posinset', 'aria-pressed', 'aria-readonly', 'aria-relevant', 'aria-required',
        'aria-roledescription', 'aria-rowcount', 'aria-rowindex', 'aria-rowspan', 'aria-selected',
        'aria-setsize', 'aria-sort', 'aria-valuemax', 'aria-valuemin', 'aria-valuenow', 'aria-valuetext'];
}

/**
 * Element-specific HTML attributes this table adds to core `post` (P-17's living-standard
 * base, Δ2, Δ5 under P-5/P-24, and the P-11 PDF object row). Core's own per-element lists
 * are kept verbatim (minus §4); these are additions only. T-1 pins that every entry here is
 * either core's or listed here, and that nothing core admits is lost unless §4 names it.
 */
function pp_content_html_element_additions(): array {
    $media = ['src', 'crossorigin', 'preload', 'autoplay', 'loop', 'muted', 'controls'];
    return [
        'a'        => ['href', 'target', 'download', 'rel', 'hreflang', 'type', 'referrerpolicy'],
        'area'     => ['alt', 'coords', 'shape', 'href', 'target', 'download', 'rel', 'referrerpolicy'],
        'audio'    => $media,
        'video'    => array_merge($media, ['poster', 'playsinline', 'width', 'height']),
        'blockquote' => ['cite'], 'q' => ['cite'], 'del' => ['cite', 'datetime'], 'ins' => ['cite', 'datetime'],
        // HTML invoker commands: built-in behaviours (show-modal, toggle-popover, close), no
        // script; `commandfor` is an id reference, so E12 governs it like popovertarget.
        'button'   => ['disabled', 'name', 'type', 'value', 'popovertarget', 'popovertargetaction',
            'command', 'commandfor'],
        'bdi'      => [],
        'canvas'   => ['width', 'height'],
        'col'      => ['span'], 'colgroup' => ['span'],
        'data'     => ['value'],
        'details'  => ['open', 'name'],
        'dialog'   => ['open', 'closedby'],
        // Δ2 adds srcset/sizes/decoding/fetchpriority; the rest is the living standard.
        'img'      => ['alt', 'src', 'srcset', 'sizes', 'crossorigin', 'usemap', 'ismap', 'width',
            'height', 'referrerpolicy', 'decoding', 'loading', 'fetchpriority'],
        'label'    => ['for'],
        'li'       => ['value'],
        'map'      => ['name'],
        'meter'    => ['value', 'min', 'max', 'low', 'high', 'optimum'],
        'ol'       => ['reversed', 'start', 'type'],
        'progress' => ['value', 'max'],
        'td'       => ['colspan', 'rowspan', 'headers'],
        'th'       => ['colspan', 'rowspan', 'headers', 'scope', 'abbr'],
        'time'     => ['datetime'],
        'track'    => ['default', 'kind', 'label', 'src', 'srclang'],
        // Δ2: responsive images and media sources.
        'picture'  => [],
        'source'   => ['type', 'src', 'srcset', 'sizes', 'media', 'width', 'height'],
        // P-11: the same-install PDF object, a named Δ2 exception to E3 (gated below).
        'object'   => ['data', 'type', 'width', 'height'],
    ];
}

/**
 * The HTML-namespace admission table: element => [attribute => true], WITHOUT the global
 * attributes (pp_content_html_global_attributes()). Derived from core `post` on WordPress
 * 7.0 (lib/content-tables/core-post-wp-7.0.json) minus §4, plus the additions above.
 */
function pp_content_html_table(): array {
    static $table = null;
    if ($table !== null) {
        return $table;
    }
    $core     = _pp_content_data_table('core-post-wp-7.0.json')['tags'] ?? [];
    $globals  = pp_content_html_global_attributes() + ['data-*' => true];
    $excluded = pp_content_excluded_elements();
    $table    = [];
    foreach ($core as $tag => $attrs) {
        if (isset($excluded[$tag]) && $tag !== 'object') {
            continue;
        }
        $table[$tag] = [];
        foreach ((array) $attrs as $attr => $_) {
            if (!isset($globals[$attr])) {
                $table[$tag][$attr] = true;
            }
        }
    }
    foreach (pp_content_html_element_additions() as $tag => $attrs) {
        $table[$tag] = ($table[$tag] ?? []) + array_fill_keys($attrs, true);
    }
    foreach ($table as $tag => $attrs) {
        $table[$tag] = array_diff_key($attrs, pp_content_excluded_attributes());
    }
    ksort($table);
    return $table;
}

/**
 * P-2 B+: the INLINE set. Closed by ruling: the ruling names span's attributes and no
 * others. Titles and headings take the same set WITHOUT `a` (pp_content_heading_table()).
 */
function pp_content_inline_table(): array {
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    return $cache = [
        'a' => ['href' => true, 'title' => true], 'strong' => [], 'em' => [], 'br' => [],
        'span' => ['class' => true, 'style' => true], 'sup' => [], 'sub' => [], 'small' => [],
        'mark' => [], 'code' => [],
    ];
}

/**
 * P-2 B+ for titles and headings (the Sprint-6 titles ruling, 2026-10-05): `strong`, `em`,
 * `br` and the widening set, and NO `a` — a template may render a title inside a link, and
 * a link inside a link cannot be parsed as written.
 */
function pp_content_heading_table(): array {
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $table = pp_content_inline_table();
    unset($table['a']);
    return $cache = $table;
}

/** The P-2 table for an inline or heading sink. */
function _pp_content_p2_table(string $sink): array {
    return $sink === 'heading' ? pp_content_heading_table() : pp_content_inline_table();
}

/** The P-2 refusal text for an inline or heading sink. */
function _pp_content_p2_text(string $sink): string {
    return $sink === 'heading'
        ? 'a title or heading admits only strong, em, br, span (class, style), sup, sub, small, mark and code (no links)'
        : pp_content_clause_text('P-2');
}

/**
 * Δ1 under P-18: every static element of SVG 1.1 and SVG 2, minus §4 (E5), keyed by the
 * lowercased local name with its canonical (camel-case) spelling as the value. The printed
 * contract list documents the rule; this is the rule. `metadata` is admitted with its
 * editor-namespace descendants as inert (P-18).
 */
function pp_content_svg_elements(): array {
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $names = ['svg', 'g', 'defs', 'symbol', 'use', 'title', 'desc', 'metadata', 'switch', 'view',
        'path', 'rect', 'circle', 'ellipse', 'line', 'polyline', 'polygon', 'text', 'tspan', 'textPath',
        'linearGradient', 'radialGradient', 'stop', 'clipPath', 'mask', 'pattern', 'marker', 'a',
        'filter', 'feBlend', 'feColorMatrix', 'feComponentTransfer', 'feComposite', 'feConvolveMatrix',
        'feDiffuseLighting', 'feDisplacementMap', 'feDistantLight', 'feDropShadow', 'feFlood', 'feFuncA',
        'feFuncB', 'feFuncG', 'feFuncR', 'feGaussianBlur', 'feMerge', 'feMergeNode', 'feMorphology',
        'feOffset', 'fePointLight', 'feSpecularLighting', 'feSpotLight', 'feTile', 'feTurbulence'];
    $out = [];
    foreach ($names as $n) {
        $out[strtolower($n)] = $n;
    }
    return $cache = $out;
}

/**
 * Δ1 under P-18: the static SVG attribute set (core, conditional-processing, presentation,
 * geometry and every element-specific static attribute of SVG 1.1 + SVG 2), admitted on
 * every admitted SVG element. An attribute that does not apply to its element is inert,
 * and refusing it would refuse real exports for nothing (P-18). Lowercased => canonical.
 * `href`/`xlink:href` are NOT here: E5/P-12 gate them per element.
 */
function pp_content_svg_attributes(): array {
    static $out = null;
    if ($out !== null) {
        return $out;
    }
    $names = [
        // core + conditional processing + document attributes exported icons carry
        'id', 'class', 'style', 'lang', 'tabindex', 'autofocus', 'xml:lang', 'xml:space', 'role',
        'requiredExtensions', 'requiredFeatures', 'systemLanguage', 'version', 'baseProfile',
        'focusable', 'zoomAndPan', 'xmlns', 'xmlns:xlink', 'enable-background',
        // presentation attributes
        'alignment-baseline', 'baseline-shift', 'clip', 'clip-path', 'clip-rule', 'color',
        'color-interpolation', 'color-interpolation-filters', 'color-profile', 'color-rendering', 'cursor',
        'direction', 'display', 'dominant-baseline', 'fill', 'fill-opacity', 'fill-rule', 'filter',
        'flood-color', 'flood-opacity', 'font-family', 'font-size', 'font-size-adjust', 'font-stretch',
        'font-style', 'font-variant', 'font-weight', 'glyph-orientation-horizontal',
        'glyph-orientation-vertical', 'image-rendering', 'kerning', 'letter-spacing', 'lighting-color',
        'marker-end', 'marker-mid', 'marker-start', 'mask', 'mask-type', 'opacity', 'overflow',
        'paint-order', 'pointer-events', 'shape-rendering', 'stop-color', 'stop-opacity', 'stroke',
        'stroke-dasharray', 'stroke-dashoffset', 'stroke-linecap', 'stroke-linejoin', 'stroke-miterlimit',
        'stroke-opacity', 'stroke-width', 'text-anchor', 'text-decoration', 'text-overflow',
        'text-rendering', 'transform', 'transform-origin', 'unicode-bidi', 'vector-effect',
        'visibility', 'white-space', 'word-spacing', 'writing-mode',
        // geometry and element-specific static attributes
        'cx', 'cy', 'r', 'rx', 'ry', 'x', 'y', 'x1', 'y1', 'x2', 'y2', 'width', 'height', 'd', 'points',
        'pathLength', 'viewBox', 'preserveAspectRatio', 'offset', 'gradientUnits', 'gradientTransform',
        'spreadMethod', 'fx', 'fy', 'fr', 'clipPathUnits', 'maskUnits', 'maskContentUnits',
        'patternUnits', 'patternContentUnits', 'patternTransform', 'markerWidth', 'markerHeight',
        'refX', 'refY', 'orient', 'markerUnits', 'filterUnits', 'primitiveUnits', 'filterRes',
        'in', 'in2', 'result', 'stdDeviation', 'dx', 'dy', 'operator', 'k1', 'k2', 'k3', 'k4', 'type',
        'values', 'tableValues', 'slope', 'intercept', 'amplitude', 'exponent', 'mode', 'baseFrequency',
        'numOctaves', 'seed', 'stitchTiles', 'scale', 'xChannelSelector', 'yChannelSelector', 'radius',
        'edgeMode', 'kernelMatrix', 'order', 'divisor', 'bias', 'targetX', 'targetY',
        'kernelUnitLength', 'preserveAlpha', 'surfaceScale', 'diffuseConstant', 'specularConstant',
        'specularExponent', 'azimuth', 'elevation', 'z', 'pointsAtX', 'pointsAtY', 'pointsAtZ',
        'limitingConeAngle', 'lengthAdjust', 'textLength', 'rotate', 'startOffset', 'method',
        'spacing', 'side', 'path',
        // svg <a>
        'target', 'download', 'rel', 'hreflang', 'referrerpolicy',
    ];
    $out = [];
    foreach (array_merge($names, pp_content_aria_attributes()) as $n) {
        $out[strtolower($n)] = $n;
    }
    return $out;
}

/** P-12 + Δ1: the SVG elements that may carry a same-document fragment href. */
function pp_content_svg_fragment_href_elements(): array {
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    return $cache = array_fill_keys(['use', 'lineargradient', 'radialgradient', 'pattern', 'filter', 'textpath'], true);
}

/** Δ1's reference-bearing presentation attributes (fragment `url(#id)` admitted). */
function pp_content_svg_reference_attributes(): array {
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    return $cache = array_fill_keys(['fill', 'stroke', 'clip-path', 'mask', 'filter', 'marker-start',
        'marker-mid', 'marker-end'], true);
}

/** MathML: core `post`'s MathML elements plus MathML Core's `none`, minus E5. */
function pp_content_math_elements(): array {
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $core = _pp_content_data_table('core-post-wp-7.0.json')['tags'] ?? [];
    $out = ['none' => true];
    foreach ($core as $tag => $_) {
        if ($tag === 'math' || $tag === 'semantics' || $tag === 'annotation' || preg_match('/^m[a-z]+$/', $tag)) {
            $out[$tag] = true;
        }
    }
    unset($out['main'], $out['map'], $out['mark'], $out['menu'], $out['meter']);
    return $cache = $out;
}

/** MathML attributes: core's MathML attribute union plus MathML Core's globals. */
function pp_content_math_attributes(): array {
    static $out = null;
    if ($out !== null) {
        return $out;
    }
    $core = _pp_content_data_table('core-post-wp-7.0.json')['tags'] ?? [];
    $out = array_fill_keys(['id', 'class', 'style', 'dir', 'displaystyle', 'scriptlevel', 'mathvariant',
        'mathbackground', 'mathcolor', 'mathsize', 'tabindex', 'autofocus', 'role', 'lang', 'title'], true);
    foreach (pp_content_math_elements() as $tag => $_) {
        foreach ((array) ($core[$tag] ?? []) as $attr => $__) {
            if ($attr !== 'data-*') {
                $out[$attr] = true;
            }
        }
    }
    foreach (pp_content_aria_attributes() as $a) {
        $out[$a] = true;
    }
    return $out;
}

/** Names a custom element may not take (HTML spec reserved names; P-23 "shadowing"). */
function pp_content_reserved_custom_names(): array {
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    return $cache = array_fill_keys(['annotation-xml', 'color-profile', 'font-face', 'font-face-src',
        'font-face-uri', 'font-face-format', 'font-face-name', 'missing-glyph'], true);
}

/** The HTML spec's valid custom element name grammar (P-23), lowercase as parsed. */
function pp_content_is_valid_custom_element_name(string $name): bool {
    if (isset(pp_content_reserved_custom_names()[$name])) {
        return false;
    }
    $pcen = '\-\.0-9_a-z\x{B7}\x{C0}-\x{D6}\x{D8}-\x{F6}\x{F8}-\x{37D}\x{37F}-\x{1FFF}\x{200C}\x{200D}'
        . '\x{203F}\x{2040}\x{2070}-\x{218F}\x{2C00}-\x{2FEF}\x{3001}-\x{D7FF}\x{F900}-\x{FDCF}'
        . '\x{FDF0}-\x{FFFD}\x{10000}-\x{EFFFF}';
    return preg_match('/^[a-z][' . $pcen . ']*-[' . $pcen . ']*\z/u', $name) === 1;
}

/** P-10: named app schemes, never a pattern. Hyperlinks only (they open an app). */
function pp_content_app_schemes(): array {
    return ['sip', 'whatsapp', 'geo', 'maps', 'signal', 'facetime'];
}

/**
 * E11, narrowed to the real clobber surface (2026-10-05 rulings on T3a 7A question 4 and the
 * cycle-6 product question), from the pinned browser probe
 * (lib/content-tables/dom-clobber-names.json; raw probe output in dom-clobber-probe.json):
 *
 *   document  Document named access OVERRIDES built-ins: an <img name> (likewise an <embed>,
 *             <form>, <iframe> or <object> name) shadows document.cookie, .forms,
 *             .getElementById … — 309 of the 310 names on document's chain (all but the
 *             unforgeable `location`). Refused as the NAME of those elements, and as the ID of an <object>.
 *   form      Form-control named access: a control's name or id becomes a property of the
 *             form that owns it (`action`, `submit`, `elements`, `name` …). Unreachable while
 *             forms are descoped (Δ5, refused by D5); the probed set is kept, pinned, for the
 *             Layer-3 forms contract that will admit them.
 *   ids       ADMITTED on every element but <object>, even an id that spells a document
 *             built-in or a window property. The probe shows an id shadows 0 of the 997 names on the window
 *             chain, and an id reaches document named access only on an <object>, or an
 *             <img> that also carries a name. The <img> name is refused (P-17). The
 *             <object> is NOT otherwise refused: P-11 admits the same-install PDF object,
 *             so an id on an <object> is judged against the document set like a name. §4
 *             admits an exclusion only for what executes, fetches, escapes or forges, and an
 *             ordinary id does none of those: `<h2 id="title">` and
 *             `<svg aria-labelledby="title">` work. An id equal to a global a page script
 *             defines or reads (`wp`, `jQuery` …) can only pre-empt it: disclosed
 *             (content_global_shadow, info), never refused.
 *
 * @return array{document: array<string,true>, form: array<string,true>, globals: array<string,true>}
 */
function pp_content_clobber_table(): array {
    static $table = null;
    if ($table === null) {
        $raw = _pp_content_data_table('dom-clobber-names.json');
        $table = [
            'document' => array_fill_keys($raw['document_builtins'] ?? [], true),
            'form'     => array_fill_keys($raw['form_builtins'] ?? [], true),
            'globals'  => array_fill_keys($raw['page_globals'] ?? [], true),
        ];
    }
    return $table;
}

/** The elements whose `name` document named access exposes (HTML "dom-document-nameditem"). */
function pp_content_named_access_elements(): array {
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    return $cache = array_fill_keys(['embed', 'form', 'iframe', 'img', 'object'], true);
}

/** E12: id-reference attributes and how many ids each carries. */
function pp_content_idref_attributes(): array {
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    return $cache = [
        'popovertarget' => 'one', 'commandfor' => 'one', 'list' => 'one',
        'aria-activedescendant' => 'one', 'aria-errormessage' => 'one',
        'aria-controls' => 'many', 'aria-describedby' => 'many', 'aria-labelledby' => 'many',
        'aria-details' => 'many', 'aria-owns' => 'many', 'aria-flowto' => 'many', 'headers' => 'many',
        'itemref' => 'many',
    ];
}

/** Clause texts, so every refusal names the rule in words as well as by number. */
function pp_content_clause_text(string $clause): string {
    $texts = [
        'E1'   => 'event handlers and core\'s data-wp-* directives execute script',
        'E2'   => 'a URL must be relative, a #fragment, or use an allowed scheme; script-bearing and off-list schemes are refused',
        'E3'   => 'nested browsing contexts are never written by an author',
        'E4'   => 'script, style and document-level elements are refused; CSS belongs in the band\'s styling',
        'E5'   => 'SVG/MathML active and cross-namespace content can execute or fetch',
        'E6'   => 'the engine-owned namespace (data-pp-*, minted and reserved ids, band anchors) cannot be forged by content',
        'E7'   => 'a style attribute runs the program\'s CSS gates',
        'E8'   => 'unknown elements are refused (hyphenated custom elements are admitted)',
        'E9'   => 'submission and navigation redirectors move where a click or submit goes',
        'E10'  => 'markup must stay inside its own container',
        'E11'  => 'an id or name that shadows a document or form property (DOM clobbering)',
        'E12'  => 'an id reference must point at an element inside the same band',
        'P-16' => 'unsupported markup',
        'P-17' => 'not an attribute of the admitted HTML/ARIA base',
        'P-2'  => 'this prop admits only a, strong, em, br, span (class, style), sup, sub, small, mark and code',
        'D1'   => 'SVG (Δ1) value gate',
        'D2'   => 'responsive images and the same-install PDF object (Δ2)',
        'D3'   => 'the style attribute runs the program\'s CSS gates (Δ3)',
        'D5'   => 'forms are descoped by the owner (2026-10-05): a form and its controls are refused until the Layer-3 forms contract admits them',
        'guard' => 'content must be a string',
        'unfiltered_html' => 'markup beyond what WordPress admits for a user without the unfiltered_html capability (the core `post` set)',
    ];
    return $texts[$clause] ?? $clause;
}

// ── THE TRUST TIER (routed item 12, ruled for T3a) ─────────────────────────────────────
//
// The widened admissions (everything beyond the core `post` kses table: SVG, forms, custom
// elements, microdata, the argued attributes, app schemes, data: images, modern CSS) are a
// power WordPress gives only to users with `unfiltered_html`. A writer without it gets core
// parity: what kses would have admitted, judged by the same walk and refused by name (clause
// unfiltered_html), never stripped. WP-CLI runs with server-level access, as every other
// PromptingPress CLI gate treats it (_pp_cli_require_apply_cap()).

/** 'full' when the writing user may write the widened set, else 'core'. */
function pp_content_write_tier(): string {
    if (defined('WP_CLI') && WP_CLI) {
        return 'full';
    }
    return function_exists('current_user_can') && current_user_can('unfiltered_html') ? 'full' : 'core';
}

/** The core `post` kses table this site applies (the live one; the pinned snapshot without WordPress). */
function pp_content_core_post_table(): array {
    if (function_exists('wp_kses_allowed_html')) {
        $live = wp_kses_allowed_html('post');
        if (is_array($live) && $live !== []) {
            return array_change_key_case($live, CASE_LOWER);
        }
    }
    return _pp_content_data_table('core-post-wp-7.0.json')['tags'] ?? [];
}

/**
 * Core parity for one element (the 'core' tier): the element must be in core `post`, each
 * attribute in core's list for it, each URL on core's protocol list, and a style attribute
 * must survive core's own CSS filter unchanged. Losses are added to $state; returns false
 * when the element itself is beyond core (the caller stops judging it).
 */
function _pp_content_core_tier_check(string $ns, string $tag, string $qual, array $values, string $where, array &$state): bool {
    $core = pp_content_core_post_table();
    $text = pp_content_clause_text('unfiltered_html');
    if ($ns === 'svg' || !isset($core[$tag]) || !is_array($core[$tag]) && $core[$tag] !== true) {
        $state['losses'][] = _pp_content_loss('<' . $qual . '>', $where, 'unfiltered_html', $text);
        return false;
    }
    $allowed = is_array($core[$tag]) ? array_change_key_case($core[$tag], CASE_LOWER) : [];
    foreach ($values as $attr => $value) {
        $attr = (string) $attr;
        // core kses's own data-* grammar (wp_kses_attr_check()): data-[a-z0-9_-]+.
        $listed = isset($allowed[$attr]) || (isset($allowed['data-*']) && preg_match('/^data-[a-z0-9_-]+\z/', $attr));
        if (!$listed) {
            $state['losses'][] = _pp_content_loss($attr . ' on <' . $qual . '>', $where, 'unfiltered_html', $text);
            continue;
        }
        if (!is_string($value)) {
            continue;
        }
        // A URL scheme beyond core's protocol list (an app scheme, a data: image): the full
        // tier only. Read by the same canonicaliser as E2, so no spelling hides a scheme.
        $c = _pp_content_url_context($tag, $attr) !== null ? pp_content_url_parse($value) : null;
        if ($c !== null && $c['scheme'] !== null
            && !in_array($c['scheme'], array_map('strtolower', wp_allowed_protocols()), true)) {
            $state['losses'][] = _pp_content_loss($attr . '="' . _pp_content_reflect($value, 60) . '" on <' . $qual . '>',
                $where, 'unfiltered_html', 'this URL scheme is beyond WordPress\'s own protocol list, which a user without unfiltered_html writes');
            continue;
        }
        if ($attr === 'style' && trim($value) !== '') {
            // Kept WHOLE: every declaration core's filter returns, as sent (normalised for
            // whitespace only), or the write is refused.
            $filtered = function_exists('safecss_filter_attr') ? (string) safecss_filter_attr($value) : null;
            if ($filtered === null || _pp_content_normalise_declarations($filtered) !== _pp_content_normalise_declarations($value)) {
                $state['losses'][] = _pp_content_loss('style="' . _pp_content_reflect($value, 60) . '" on <' . $qual . '>',
                    $where, 'unfiltered_html', 'a declaration here is beyond WordPress\'s own CSS filter (safecss_filter_attr), which a user without unfiltered_html writes');
            }
        }
    }
    return true;
}

// ── THE URL CANONICALISER (the one owner of "what URL is this", cycle-9 ruling) ─────────
//
// Every URL-judging arm (E2, srcset, the P-11 PDF object, the trust tier, the forms gate)
// reads a URL through pp_content_url_parse(), which follows the WHATWG URL parser where it
// matters to a verdict: leading/trailing C0 controls and spaces are stripped and ASCII tab
// and newline removed anywhere; in a special scheme (and in a relative reference, whose base
// is this site's http(s) page) a backslash is a slash; a URL whose scheme equals the page's
// own and has no `//` is a RELATIVE reference (`https:/wp-login.php` is `/wp-login.php`); a
// special scheme with another name skips any slashes before its authority; dot segments
// (`.`, `..` and their `%2e` spellings) are resolved. What it cannot parse cleanly (a control
// character left inside, a malformed host or port, a `file:` URL) is null, and every caller
// REFUSES null: never passed through.

/** The scheme this site's pages are served under (the base of every relative reference). */
function _pp_content_site_url_parts(): array {
    static $cache = [];
    $home = function_exists('home_url') ? (string) home_url('/') : '';
    if (isset($cache[$home])) {
        return $cache[$home];
    }
    $p = parse_url($home);
    $scheme = is_array($p) && isset($p['scheme']) ? strtolower($p['scheme']) : 'https';
    return $cache[$home] = [
        'scheme' => $scheme,
        'host'   => is_array($p) && isset($p['host']) ? strtolower($p['host']) : '',
        'port'   => is_array($p) && isset($p['port']) ? (int) $p['port'] : ($scheme === 'http' ? 80 : 443),
    ];
}

/**
 * Parses a URL the way a browser does, as far as a verdict needs.
 *
 * @return array{kind:string, scheme:?string, host:?string, port:?int, path:string, query:?string, fragment:?string, text:string}|null
 *         kind: opaque (a non-special scheme: mailto:, sip:, data:, javascript: …), absolute
 *         (a special scheme with an authority), network (`//host/…`), relative (a path,
 *         query or fragment reference). Null: not parseable cleanly; refuse it.
 */
function pp_content_url_parse(string $url): ?array {
    $u = preg_replace('/^[\x00-\x20]+|[\x00-\x20]+\z/', '', $url) ?? '';
    $u = str_replace(["\t", "\n", "\r"], '', $u);
    if (preg_match('/[\x00-\x1F\x7F]/', $u)) {
        return null;
    }
    $special = ['http' => 80, 'https' => 443, 'ws' => 80, 'wss' => 443, 'ftp' => 21, 'file' => 0];
    $site = _pp_content_site_url_parts();
    $scheme = null;
    $rest = $u;
    if (preg_match('/^([a-zA-Z][a-zA-Z0-9+.\-]*):(.*)\z/s', $u, $m)) {
        $scheme = strtolower($m[1]);
        $rest = $m[2];
    }
    if ($scheme !== null && !isset($special[$scheme])) {
        return ['kind' => 'opaque', 'scheme' => $scheme, 'host' => null, 'port' => null, 'path' => $rest,
            'query' => null, 'fragment' => null, 'text' => $u];
    }
    if ($scheme === 'file') {
        return null;
    }
    $rest = str_replace('\\', '/', $rest);
    if ($scheme !== null && $scheme === $site['scheme'] && !str_starts_with($rest, '//')) {
        $scheme = null; // the page's own scheme without an authority: a relative reference
    } elseif ($scheme !== null) {
        $rest = '//' . ltrim($rest, '/');
    }
    $kind = 'relative';
    $host = null;
    $port = null;
    if (str_starts_with($rest, '//')) {
        $after = substr($rest, 2);
        $end = strcspn($after, '/?#');
        $authority = substr($after, 0, $end);
        $rest = substr($after, $end);
        $at = strrpos($authority, '@');
        if ($at !== false) {
            $authority = substr($authority, $at + 1);
        }
        if (!preg_match('/^(\[[0-9a-fA-F:.]+\]|[^:\[\]\/?#@\s%<>^|"\\\\]+)(?::([0-9]*))?\z/u', $authority, $hm)) {
            return null;
        }
        $host = function_exists('mb_strtolower') ? mb_strtolower($hm[1], 'UTF-8') : strtolower($hm[1]);
        if ($host === '' || (isset($hm[2]) && $hm[2] !== '' && (int) $hm[2] > 65535)) {
            return null;
        }
        $scheme_for_port = $scheme ?? $site['scheme'];
        $port = isset($hm[2]) && $hm[2] !== '' ? (int) $hm[2] : ($special[$scheme_for_port] ?? null);
        $kind = $scheme === null ? 'network' : 'absolute';
        if ($rest === '' || $rest[0] !== '/') {
            $rest = '/' . $rest;
        }
    }
    $fragment = null;
    $hash = strpos($rest, '#');
    if ($hash !== false) {
        $fragment = substr($rest, $hash + 1);
        $rest = substr($rest, 0, $hash);
    }
    $query = null;
    $q = strpos($rest, '?');
    if ($q !== false) {
        $query = substr($rest, $q + 1);
        $rest = substr($rest, 0, $q);
    }
    return ['kind' => $kind, 'scheme' => $scheme, 'host' => $host, 'port' => $port,
        'path' => _pp_content_remove_dot_segments($rest), 'query' => $query, 'fragment' => $fragment, 'text' => $u];
}

/**
 * The WHATWG path's dot segments resolved: `.` and `%2e` are dropped, `..` and its `%2e`
 * spellings pop a segment (never above the root). In a relative path a leading `..` that
 * nothing can pop is kept: the page it resolves against is not known.
 */
function _pp_content_remove_dot_segments(string $path): string {
    if ($path === '') {
        return '';
    }
    $absolute = $path[0] === '/';
    $segments = explode('/', $absolute ? substr($path, 1) : $path);
    $out = [];
    $last = count($segments) - 1;
    foreach ($segments as $i => $seg) {
        $lower = strtolower($seg);
        if ($lower === '.' || $lower === '%2e') {
            if ($i === $last) {
                $out[] = '';
            }
            continue;
        }
        if (in_array($lower, ['..', '.%2e', '%2e.', '%2e%2e'], true)) {
            if ($out !== [] && end($out) !== '..') {
                array_pop($out);
            } elseif (!$absolute) {
                $out[] = '..';
            }
            if ($i === $last) {
                $out[] = '';
            }
            continue;
        }
        $out[] = $seg;
    }
    return ($absolute ? '/' : '') . implode('/', $out);
}

/** A style value's declarations, whitespace-normalised, for an exact comparison. */
function _pp_content_normalise_declarations(string $css): array {
    return array_map(static function (string $decl): string {
        $colon = strpos($decl, ':');
        $prop = strtolower(trim($colon === false ? $decl : substr($decl, 0, $colon)));
        $value = $colon === false ? '' : (preg_replace('/\s+/', ' ', trim(substr($decl, $colon + 1))) ?? '');
        return $prop . ':' . $value;
    }, _pp_content_split_declarations($css));
}

// ── THE PREDICATE ─────────────────────────────────────────────────────────────────────

/**
 * The one content predicate (§2.1).
 *
 * @param  mixed  $bytes  The stored or submitted prop value.
 * @param  string $sink   rich | rich_cell | inline | heading | plain.
 * @param  array  $ctx    Cross-band facts, as pp_content_band_context() builds them (keyed sets
 *                        and page-wide counts). A direct caller may instead pass plain lists:
 *                        `anchors` (every band anchor in the composition), `band_ids` (ids
 *                        authored anywhere in this band, plus its own anchor), `band_map_names`
 *                        (map names in this band, for E12's usemap), `other_ids` (ids and
 *                        anchors carried by other bands), `other_details_names` (details name
 *                        groups of other bands), `other_refs` (ids other bands refer to).
 * @return array{html:string, losses:list<array{construct:string,where:string,clause:string,message:string}>, notes:list<string>, ids:list<string>}
 */
function pp_content_sanitize($bytes, string $sink, array $ctx = []): array {
    return _pp_content_finish(_pp_content_check($bytes, $sink, $ctx), $ctx);
}

/**
 * The predicate's two views of one value (lexical scan + tree walk), WITHOUT the cross-band
 * E12 resolution. The write gate runs this once per judged value and reads the band's
 * facts (ids, references, map and details names) from the result, so the facts the
 * cross-band rules use are the ones the walk saw (_pp_content_finish() completes it).
 *
 * @param  array $ctx  As for pp_content_sanitize(); the walk reads only the band anchors (E6).
 * @return array  The predicate state, or ['final' => result] when no walk runs.
 */
function _pp_content_check($bytes, string $sink, array $ctx = []): array {
    // §2.1 step 1, the #730 guard: refused by returning, never by throwing.
    if (!is_string($bytes)) {
        return ['final' => ['html' => '', 'losses' => [_pp_content_loss('value', '', 'guard',
            'content must be a string; got ' . gettype($bytes))], 'notes' => [], 'ids' => []]];
    }
    // §3.3: a PLAIN prop is escaped and never parsed, so it is never a Loss.
    if ($sink === 'plain') {
        return ['final' => ['html' => esc_html($bytes), 'losses' => [], 'notes' => [], 'ids' => []]];
    }
    if ($bytes === '') {
        return ['final' => ['html' => '', 'losses' => [], 'notes' => [], 'ids' => []]];
    }

    $state = [
        'sink'   => $sink,
        'ctx'    => _pp_content_ctx($ctx),
        'losses' => [],
        'notes'  => [],
        'ids'    => [],
        'refs'   => [],
        'details_names' => [],
        'map_names' => [],
        'lexical_starts' => [],
        'open' => [],               // the tree walk's open-element counts (it resets them)
        'html' => '',
    ];

    // Fail closed: without its derived tables the predicate would admit too much (E11's
    // name list) or too little (the base). A partial deploy refuses rather than guesses.
    if (pp_content_clobber_table()['document'] === [] || (_pp_content_data_table('core-post-wp-7.0.json')['tags'] ?? []) === []) {
        return ['final' => ['html' => '', 'losses' => [_pp_content_loss('the prop', '', 'guard',
            'the content tables in lib/content-tables/ could not be loaded, so content cannot be checked')],
            'notes' => [], 'ids' => []]];
    }

    _pp_content_lexical_scan($bytes, $state);
    $state['html'] = _pp_content_tree_walk($bytes, $state);
    return $state;
}

/**
 * Completes a _pp_content_check() state: E12 against the cross-band context, then the
 * whole-prop clauses.
 */
function _pp_content_finish(array $state, array $ctx = []): array {
    if (isset($state['final'])) {
        return $state['final'];
    }
    $state['ctx'] = _pp_content_ctx($ctx);
    // A walk cut short (M-8, P-16) saw only part of the prop: its references are not
    // resolved against a partial id set (the prop is refused whole anyway).
    $whole = false;
    foreach ($state['losses'] as $loss) {
        $whole = $whole || in_array($loss['clause'], PP_CONTENT_WHOLE_PROP_CLAUSES, true);
    }
    if (!$whole) {
        _pp_content_resolve_references($state);
    }

    $html = $state['html'];
    $losses = _pp_content_unique_losses($state['losses']);
    foreach ($losses as $loss) {
        if (in_array($loss['clause'], PP_CONTENT_WHOLE_PROP_CLAUSES, true)) {
            $html = '';
            break;
        }
    }
    return [
        'html'   => $html,
        'losses' => $losses,
        'notes'  => array_values(array_unique($state['notes'])),
        'ids'    => array_values(array_unique($state['ids'])),
    ];
}

/** Builds one Loss: a fact, not advice (§2.1). */
function _pp_content_loss(string $construct, string $where, string $clause, string $reason): array {
    $construct = _pp_content_reflect($construct, 120);
    $where     = _pp_content_reflect($where, 400);
    return [
        'construct' => $construct,
        'where'     => $where,
        'clause'    => $clause,
        'message'   => sprintf('%s%s is refused by %s (%s)',
            $construct,
            $where !== '' ? ' at ' . $where : '',
            preg_match('/^D(\d)\z/', $clause, $d) ? 'Δ' . $d[1] : $clause,
            $reason),
    ];
}

/** Author text quoted in a message: control characters stripped and length bounded. */
function _pp_content_reflect(string $text, int $max): string {
    // The wrapper's per-call end marker can ride into a construct the parser glued to it
    // (a title of `a<b`); it is the gate's own text, never the author's.
    $text = preg_replace('/<!--pp-end-[0-9a-f]{16}.*/s', '', $text) ?? $text;
    $text = preg_replace('/[\x00-\x1F\x7F]/', ' ', $text) ?? '';
    if (function_exists('mb_strlen') && mb_strlen($text, 'UTF-8') > $max) {
        return mb_substr($text, 0, $max, 'UTF-8') . '…';
    }
    return strlen($text) > $max * 4 ? substr($text, 0, $max) . '…' : $text;
}

function _pp_content_unique_losses(array $losses): array {
    // The lexical and tree views can report one construct twice. Keep one per
    // (clause, construct), preferring the tree's element path as the locator.
    $out = [];
    foreach ($losses as $loss) {
        $key = $loss['clause'] . "\0" . $loss['construct'];
        if (!isset($out[$key]) || strlen($loss['where']) > strlen($out[$key]['where'])) {
            $out[$key] = $loss;
        }
    }
    return array_values($out);
}

/**
 * The lexical view: every tag token the tokenizer produces, including the ones the tree
 * builder ignores. Judges only what does not depend on namespace or tree position.
 */
function _pp_content_lexical_scan(string $bytes, array &$state): void {
    $p = new WP_HTML_Tag_Processor($bytes);
    $known = pp_content_html_table() + pp_content_excluded_elements() + pp_content_svg_elements()
        + pp_content_math_elements() + pp_content_excluded_foreign_elements();
    $inline = in_array($state['sink'], ['inline', 'heading'], true);
    // No model of the open-element stack here: what the parser holds open (the form element
    // pointer, the nesting depth) is read from the tree walk's own state, never re-derived
    // from tokens (rulings of 2026-10-05, cycle 7).
    while ($p->next_token()) {
        if ($p->get_token_type() !== '#tag') {
            continue;
        }
        $tag = strtolower((string) $p->get_tag());
        // The lexical view has no element path; the tree view supplies the locator (see
        // _pp_content_unique_losses()).
        if (in_array($tag, ['html', 'head', 'body'], true)) {
            // An in-body <html>/<body> start tag merges its attributes onto the page's real
            // element; a closer moves the parser into after-body. Either way: E4.
            $state['losses'][] = _pp_content_loss('<' . ($p->is_tag_closer() ? '/' : '') . $tag . '>', '', 'E4',
                pp_content_clause_text('E4'));
            continue;
        }
        if ($p->is_tag_closer()) {
            continue;
        }
        $start_key = _pp_content_start_key($p, $tag);
        $state['lexical_starts'][$start_key] = ($state['lexical_starts'][$start_key] ?? 0) + 1;
        // THE TITLE BLIND SPOT. This tokenizer reads every <title> as RCDATA, while inside
        // <svg> the tree builder reads it as an HTML integration point, so markup in an SVG
        // title can carry a tag that NEITHER view reports (an in-body <body onload> is
        // dropped by the tree builder and merged onto the real <body> by a browser). SVG
        // title is text-only (Δ1) and HTML title is E4, so a tag-open inside any title's
        // text is refused here.
        // Judged on the tokenizer's own title boundaries (a separate regex drifts from them:
        // `</titlex>` does not end a title). The text is reported decoded, so an escaped
        // `&lt;b&gt;` in a title is refused too: a cost accepted for exactness, and the
        // message says so.
        if ($tag === 'title' && preg_match('/<[A-Za-z!\/?]/', $p->get_modifiable_text())) {
            $state['losses'][] = _pp_content_loss('markup inside <title>', '', 'D1',
                'SVG title and desc are text-only; markup in a title, or an escaped "<" followed by a letter, is refused');
        }
        $excluded = pp_content_excluded_elements()[$tag] ?? null;
        if ($excluded !== null && $tag !== 'title' && $tag !== 'object') {
            $state['losses'][] = _pp_content_loss('<' . $tag . '>', '', $excluded, pp_content_clause_text($excluded));
        }
        if (!isset($known[$tag]) && !pp_content_is_valid_custom_element_name($tag) && !str_contains($tag, ':')) {
            $state['losses'][] = _pp_content_unknown_element_loss($tag, '');
        } elseif ($inline && !isset(_pp_content_p2_table($state['sink'])[$tag])) {
            $state['losses'][] = _pp_content_loss('<' . $tag . '>', '', 'P-2', _pp_content_p2_text($state['sink']));
        }
        foreach (($p->get_attribute_names_with_prefix('') ?? []) as $attr) {
            $value = $p->get_attribute($attr);
            _pp_content_check_universal_attribute($tag, (string) $attr, $value, '', $state);
        }
    }
    // A tokenizer that stopped on an unfinished token (a prop ending inside a tag, comment
    // or text-only element, or a self-closed <title/> it reads as an open RCDATA title)
    // judged nothing after that point, while the template's next `>` completes the token in
    // the page. What it could not read cannot be admitted.
    if ($p->paused_at_incomplete_token()) {
        $state['losses'][] = _pp_content_loss('the prop', '', 'P-16',
            'unsupported markup: it ends inside an unfinished tag, comment or text-only element (or a self-closed <title/>), '
            . 'so the rest of it cannot be read; close what it opened');
    }
}

function _pp_content_unknown_element_loss(string $tag, string $where): array {
    $hint = preg_match('/^[a-z][a-z0-9]*\z/', $tag)
        ? 'unknown elements are refused; a custom element needs a hyphen in its name'
        : 'this is not a valid element name; to show a literal "<" write &lt;';
    return _pp_content_loss('<' . $tag . '>', $where, 'E8', $hint);
}

/**
 * Attribute rules that hold on every element in every namespace and in both views.
 * Returns true when the attribute was refused (so the caller stops judging it).
 */
function _pp_content_check_universal_attribute(string $tag, string $attr, $value, string $where, array &$state): bool {
    $attr = strtolower($attr);
    $construct = $attr . ' on <' . $tag . '>';
    if (strncmp($attr, 'on', 2) === 0) {
        $state['losses'][] = _pp_content_loss($construct, $where, 'E1', pp_content_clause_text('E1'));
        return true;
    }
    if (strncmp($attr, 'data-wp-', 8) === 0) {
        $state['losses'][] = _pp_content_loss($construct, $where, 'E1', pp_content_clause_text('E1'));
        return true;
    }
    if (strncmp($attr, 'data-pp-', 8) === 0) {
        $state['losses'][] = _pp_content_loss($construct, $where, 'E6', pp_content_clause_text('E6'));
        return true;
    }
    $excluded = pp_content_excluded_attributes()[$attr] ?? null;
    if ($excluded !== null) {
        $state['losses'][] = _pp_content_loss($construct, $where, $excluded, pp_content_clause_text($excluded));
        return true;
    }
    if (!is_string($value)) {
        return false;
    }
    // E12 / HTML: an id may not contain ASCII whitespace, and neither may a single-id
    // reference (a browser matches it untrimmed, so a padded one would bind to a padded id
    // nothing else can see). Refusing both removes the trimmed-vs-untrimmed class whole.
    $single_ref = (pp_content_idref_attributes()[$attr] ?? '') === 'one' || ($attr === 'for' && $tag === 'label');
    if (($attr === 'id' || $single_ref) && preg_match('/[\t\n\f\r ]/', $value)) {
        $state['losses'][] = _pp_content_loss($attr . '="' . _pp_content_reflect($value, 60) . '" on <' . $tag . '>',
            $where, 'E12', $attr === 'id' ? 'an id may not contain whitespace (HTML forbids it)'
                : 'a reference to one id may not contain whitespace (the browser matches it untrimmed)');
        return true;
    }
    if ($attr === 'style') {
        foreach (pp_content_style_losses($value) as $reason) {
            $state['losses'][] = _pp_content_loss('style="' . _pp_content_reflect($value, 60) . '" on <' . $tag . '>',
                $where, 'D3', $reason);
        }
        return false;
    }
    // URL-valued attributes, judged in both views. The tree walk refines by element.
    $url_context = _pp_content_url_context($tag, $attr);
    if ($url_context !== null) {
        $reason = $attr === 'srcset'
            ? pp_content_srcset_loss($value)
            : (($attr === 'itemtype') ? _pp_content_url_list_loss($value) : pp_content_url_loss($value, $url_context));
        if ($reason !== null) {
            $state['losses'][] = _pp_content_loss($attr . '="' . _pp_content_reflect($value, 60) . '" on <' . $tag . '>',
                $where, 'E2', $reason);
            return true;
        }
    }
    return false;
}

/**
 * Which E2 context a URL attribute is judged in, or null when it is not a URL attribute.
 *   link     a hyperlink: wp_allowed_protocols() + P-10's app schemes
 *   img-src  <img src>: also P-10's raster data: images
 *   fetch    every other URL attribute: wp_allowed_protocols()
 */
function _pp_content_url_context(string $tag, string $attr): ?string {
    switch ($attr) {
        case 'href':
        case 'xlink:href':
            return in_array($tag, ['a', 'area'], true) ? 'link' : 'fetch';
        case 'src':
            return $tag === 'img' ? 'img-src' : 'fetch';
        case 'srcset':
        case 'poster':
        case 'cite':
        case 'action':
        case 'data':
        case 'longdesc':
        case 'background':
        case 'usemap':
        case 'itemid':
        case 'itemtype':
        case 'manifest':
        case 'icon':
        case 'codebase':
        case 'archive':
        case 'classid':
        case 'profile':
            return 'fetch';
    }
    return null;
}

/**
 * E2 for one URL, read by the shared canonicaliser (pp_content_url_parse(); entities are
 * already decoded by the HTML API). A URL it cannot parse is refused. Never rewritten
 * (§1.3's rewrite-to-relative is pinned absent): the answer is accept or refuse. Returns the
 * refusal reason or null.
 */
function pp_content_url_loss(string $url, string $context): ?string {
    $c = pp_content_url_parse($url);
    if ($c === null) {
        return 'this URL cannot be read the way a browser reads it (a control character, or a malformed host or port), so it is refused';
    }
    if ($c['scheme'] === null) {
        return null; // relative, protocol-relative (P-4 option A) or #fragment
    }
    $scheme = $c['scheme'];
    if ($scheme === 'data') {
        if ($context === 'img-src') {
            if (strlen($c['text']) > PP_CONTENT_DATA_IMAGE_MAX_BYTES) {
                return 'a data: image is limited to ' . PP_CONTENT_DATA_IMAGE_MAX_BYTES . ' bytes (P-10)';
            }
            if (preg_match('#^data:image/(png|jpeg|gif|webp|avif);base64,[A-Za-z0-9+/]+={0,2}\z#i', $c['text'])) {
                return null;
            }
            return 'only base64 data:image/png, jpeg, gif, webp or avif is admitted in <img src>, never SVG (P-10)';
        }
        return 'data: URLs are admitted only as raster images in <img src> (P-10)';
    }
    if (in_array($scheme, array_map('strtolower', wp_allowed_protocols()), true)) {
        return null;
    }
    if ($context === 'link' && in_array($scheme, pp_content_app_schemes(), true)) {
        return null;
    }
    return sprintf('the "%s:" scheme is not allowed here', _pp_content_reflect($scheme, 32));
}

/**
 * Splits on ASCII whitespace as HTML defines it (tab, LF, FF, CR, space): the one splitter
 * for every whitespace-separated token list (id-reference lists, URL lists, rel). PHP's
 * trim() and `\s` also strip a vertical tab and NUL, which are not HTML whitespace, so
 * neither is used on a value whose tokens the browser matches.
 *
 * @return list<string>
 */
function _pp_content_split_ws(string $value): array {
    return preg_split('/[\t\n\f\r ]+/', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [];
}

/** E2 over a space-separated URL list (microdata itemtype). */
function _pp_content_url_list_loss(string $value): ?string {
    foreach (_pp_content_split_ws($value) as $url) {
        if (($r = pp_content_url_loss($url, 'fetch')) !== null) {
            return $r;
        }
    }
    return null;
}

/**
 * E2 for `srcset`, every candidate, split by the HTML spec's "parse a srcset attribute"
 * algorithm (a URL is a run of non-whitespace; trailing commas end it; descriptors run to
 * the next comma outside parentheses). `data:` is never admitted in a candidate.
 */
function pp_content_srcset_loss(string $value): ?string {
    $len = strlen($value);
    $pos = 0;
    while ($pos < $len) {
        while ($pos < $len && strpos(" \t\n\r\f,", $value[$pos]) !== false) {
            $pos++;
        }
        if ($pos >= $len) {
            break;
        }
        $start = $pos;
        while ($pos < $len && strpos(" \t\n\r\f", $value[$pos]) === false) {
            $pos++;
        }
        $url = substr($value, $start, $pos - $start);
        $ended = false;
        if (substr($url, -1) === ',') {
            $url = rtrim($url, ',');
            $ended = true;
        }
        $reason = pp_content_url_loss($url, 'fetch');
        if ($reason !== null) {
            return 'srcset candidate: ' . $reason;
        }
        if (!$ended) {
            $depth = 0;
            while ($pos < $len) {
                $c = $value[$pos];
                if ($c === '(') {
                    $depth++;
                } elseif ($c === ')') {
                    $depth = max(0, $depth - 1);
                } elseif ($c === ',' && $depth === 0) {
                    break;
                }
                $pos++;
            }
        }
    }
    return null;
}

/**
 * Δ3: the style attribute runs the program's CSS gates, not core's list (E7, P-13, P-20,
 * M-3). Returns one reason per refused construct (empty when admitted). The value arrives
 * entity-decoded from the HTML API, which is what removes §1.3's entity-split mangling.
 *
 * @return list<string>
 */
function pp_content_style_losses(string $value): array {
    $reasons = [];
    // A line break ends a CSS string, so a quoted run with one is no string at all (and the
    // whitespace folding below would otherwise join it back into one).
    if (preg_match('/"[^"]*[\n\r\f][^"]*"|\'[^\']*[\n\r\f][^\']*\'/', $value)) {
        return ['a line break inside a quoted string ends the string in CSS, so it is refused as written (Δ3)'];
    }
    $value = str_replace(["\t", "\r", "\n"], ' ', $value);
    if (strpos($value, '\\') !== false) {
        return ['a CSS escape (backslash) is refused as written (Δ3)'];
    }
    if (!_pp_udc_delimiters_balanced($value)) {
        return ['unbalanced quotes, parentheses or brackets (an apostrophe inside a double-quoted value counts as an unpaired quote)'];
    }
    foreach (_pp_content_split_declarations($value) as $decl) {
        $colon = strpos($decl, ':');
        if ($colon === false) {
            $reasons[] = sprintf('"%s" is not a property: value declaration', _pp_content_reflect($decl, 60));
            continue;
        }
        $prop = trim(substr($decl, 0, $colon), " \f");
        $val  = trim(substr($decl, $colon + 1), " \f");
        $custom = strncmp($prop, '--', 2) === 0;
        if (!$custom && $prop !== strtolower($prop)) {
            $reasons[] = sprintf('property "%s" must be written in lowercase ("%s") (M-3)',
                _pp_content_reflect($prop, 64), _pp_content_reflect(strtolower($prop), 64));
            continue;
        }
        if ($custom) {
            if (!preg_match('/^--[A-Za-z0-9_-]{1,64}\z/', $prop)) {
                $reasons[] = sprintf('"%s" is not a valid custom property name', _pp_content_reflect($prop, 64));
                continue;
            }
            if (stripos($prop, '--pp-') === 0) {
                $reasons[] = sprintf('"%s" is an engine-owned custom property (--pp-*) (P-20)', _pp_content_reflect($prop, 64));
                continue;
            }
        } elseif (!pp_udc_valid_css_property($prop)) {
            $reasons[] = sprintf('"%s" is not a valid CSS property name', _pp_content_reflect($prop, 64));
            continue;
        }
        $excluded = pp_udc_css_excluded_properties();
        if (isset($excluded[$prop])) {
            $reasons[] = sprintf('property "%s" is excluded: %s', $prop, $excluded[$prop]);
            continue;
        }
        if (preg_match('/!\s*important/i', $val)) {
            $reasons[] = sprintf('"%s" carries !important (E7)', $prop);
            continue;
        }
        // P-13: a same-document fragment reference names nothing outside the document, so
        // it bypasses the "no url()" rule exactly as Δ1's attribute branch does.
        $probe = preg_replace('/url\(\s*(["\']?)#[A-Za-z_][A-Za-z0-9_.\-]{0,63}\1\s*\)/i', 'none', $val) ?? $val;
        $forbidden = _pp_forbidden_css_construct($probe);
        if ($forbidden !== null) {
            $reasons[] = sprintf('"%s" value %s (E7)', $prop, $forbidden);
            continue;
        }
        $function = _pp_content_unlisted_css_function($probe);
        if ($function !== null) {
            $reasons[] = sprintf('"%s" calls %s(), which is not on the admitted CSS function list (unknown functions are refused) (E7)',
                $prop, _pp_content_reflect($function, 40));
            continue;
        }
        $text_bearing = ['quotes', 'list-style-type', 'list-style', 'text-emphasis-style',
            'text-emphasis', 'hyphenate-character', 'text-overflow'];
        if (in_array($prop, $text_bearing, true) && preg_match('/["\']/', $val)) {
            $reasons[] = sprintf('"%s" with a quoted string renders that string as text; use a keyword (Δ3)', $prop);
            continue;
        }
    }
    return $reasons;
}

/**
 * CSS functions, DEFAULT-DENY (I19; cycle-9 ruling): the functions a value may call. The
 * url() family is not here: a same-document `url(#id)` is admitted by its own rule (P-13)
 * and every other url(), image(), image-set(), src(), element() and -moz-element() is
 * refused, as is any function this list does not name.
 */
function pp_content_css_functions(): array {
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
        // path(): pure geometry data (clip-path, offset-path), references nothing (ruled 2026-10-05).
        'path',
        // font-variant-alternates: name font-internal features, fetch nothing (ruled 2026-10-05).
        'stylistic', 'styleset', 'character-variant', 'swash', 'ornaments', 'annotation',
        // NOT admitted, by ruling (2026-10-05): attr() (reads attributes into CSS, a text-to-style
        // channel) and the anchor-positioning family (anchor(), anchor-size(): cross-element
        // positioning, refused until ruled); and every function this list does not name.
    ], true);
}

/**
 * The TEXT attributes the CSS function list never judges (orchestrator ruling, 2026-10-05):
 * deny-by-default sits on the CHECKED set, so every admitted SVG and MathML attribute value
 * is judged except these, whose value is prose, a token or a name, never CSS:
 *   - aria-* (labels and descriptions: `aria-label="Revenue (2024)"` is no call);
 *   - title, alttext (MathML/SVG prose), lang and xml:lang, role, tabindex;
 *   - a link's download (a filename), target, rel, hreflang and referrerpolicy (tokens);
 *   - MathML encoding (a media type);
 *   - editor-namespace attributes (inkscape:label …), inert metadata names.
 * (SVG <title>/<desc> CONTENT is text, never an attribute value, and is not judged here.)
 */
function _pp_content_is_text_attribute(string $attr): bool {
    // $attr arrives lowercased (the tokenizer folds attribute names; SVG names are folded
    // before their value gate).
    if (strncmp($attr, 'aria-', 5) === 0 || in_array($attr, ['title', 'alttext', 'lang', 'xml:lang', 'role', 'tabindex',
        'download', 'target', 'rel', 'hreflang', 'referrerpolicy', 'encoding'], true)) {
        return true;
    }
    // An editor namespace (prefix:name, not xlink/xml/xmlns).
    return str_contains($attr, ':') && !preg_match('/^(xlink|xml|xmlns):/', $attr);
}

/**
 * The first CSS function a value calls that pp_content_css_functions() does not admit, or
 * null. Quoted strings are text, not calls (`font-family: "Foo (Pro)"`), so they are
 * skipped.
 */
function _pp_content_unlisted_css_function(string $value): ?string {
    // A CSS string ends at a line break, so a quoted run never spans one: `"a⏎ paint(x) "`
    // is a broken string followed by a live call, and the call is judged.
    $value = preg_replace('/"[^"\n\r\f]*"|\'[^\'\n\r\f]*\'/', '""', $value) ?? $value;
    if (preg_match_all('/([a-zA-Z_\\-][a-zA-Z0-9_\\-]*)\s*\(/', $value, $m)) {
        foreach ($m[1] as $name) {
            if (!isset(pp_content_css_functions()[strtolower($name)])) {
                return strtolower($name);
            }
        }
    }
    return null;
}

/** Splits a style value on `;` outside quotes and parentheses; empty declarations drop. */
function _pp_content_split_declarations(string $value): array {
    $out = [];
    $buf = '';
    $quote = '';
    $depth = 0;
    $len = strlen($value);
    for ($i = 0; $i < $len; $i++) {
        $c = $value[$i];
        if ($quote !== '') {
            if ($c === $quote) {
                $quote = '';
            }
        } elseif ($c === '"' || $c === "'") {
            $quote = $c;
        } elseif ($c === '(') {
            $depth++;
        } elseif ($c === ')') {
            $depth = max(0, $depth - 1);
        } elseif ($c === ';' && $depth === 0) {
            if (trim($buf) !== '') {
                $out[] = trim($buf);
            }
            $buf = '';
            continue;
        }
        $buf .= $c;
    }
    if (trim($buf) !== '') {
        $out[] = trim($buf);
    }
    return $out;
}

/**
 * Δ1's value gate for every admitted SVG attribute that is not a URL and not `style`.
 */
function pp_content_svg_value_loss(string $attr, string $value): ?string {
    if ($attr === 'xmlns') {
        return $value === 'http://www.w3.org/2000/svg' ? null : 'xmlns must be exactly http://www.w3.org/2000/svg';
    }
    if ($attr === 'xmlns:xlink') {
        return $value === 'http://www.w3.org/1999/xlink' ? null : 'xmlns:xlink must be exactly http://www.w3.org/1999/xlink';
    }
    if ($attr === 'xml:space') {
        return in_array($value, ['default', 'preserve'], true) ? null : 'xml:space must be default or preserve';
    }
    if (isset(pp_content_svg_reference_attributes()[$attr])
        && preg_match('/^url\(\s*(["\']?)#[A-Za-z_][A-Za-z0-9_.\-]{0,63}\1\s*\)\z/', trim($value))) {
        return null;
    }
    // Tab, LF, FF and CR are whitespace (multi-line path data, as Illustrator writes it);
    // every other control character is refused.
    if (preg_match('/[\x00-\x08\x0B\x0E-\x1F\x7F<>{};\\\\]/', $value) || strpos($value, '/*') !== false || strpos($value, '*/') !== false) {
        return 'SVG attribute values may not contain control characters, < > { } ; \\ or comment delimiters (Δ1)';
    }
    // Every way a value can name an external resource, not only `url(`: the shared CSS
    // gate's function set (image-set(), image() and src() take a bare-string URL), plus
    // expression() and a javascript: scheme. Checked after CSS unescaping and with
    // whitespace removed, so neither an escape nor a space hides one.
    $unescaped = strtolower(preg_replace('/\s+/', '', _pp_css_unescape($value)) ?? $value);
    if (preg_match('/url\(|image-set\(|(?<![a-z-])image\(|(?<![a-z-])src\(|expression\(|@import|javascript:/', $unescaped, $m)) {
        return sprintf('"%s" is refused in an SVG attribute value; only a same-document url(#id) on a reference attribute is admitted (Δ1)', $m[0]);
    }
    $function = _pp_content_is_text_attribute($attr) ? null : _pp_content_unlisted_css_function(_pp_css_unescape($value));
    if ($function !== null) {
        return sprintf('%s() is not on the admitted CSS function list (unknown functions are refused) (Δ1)', _pp_content_reflect($function, 40));
    }
    return null;
}

/**
 * The per-sink wrapper the prop is parsed inside (§2.1 step 5): the template's chain
 * down to the sink container, then a next-band sentinel. Only element TYPES matter to
 * the parser, so classes are omitted; the container and sentinel carry a per-call nonce
 * the author cannot know.
 *
 * @return array{0:string,1:string,2:string,3:list<string>} [open, close, container tag, tail closers]
 */
function _pp_content_wrapper(string $sink, string $nonce): array {
    $mark = ' data-pp-sink-' . $nonce . '=""';
    switch ($sink) {
        case 'rich_cell':
            return ['<div><table><tbody><tr><td' . $mark . '>', '</td></tr></tbody></table></div>', 'TD',
                ['TR', 'TBODY', 'TABLE', 'DIV', 'SECTION']];
        case 'inline':
            return ['<div><p' . $mark . '>', '</p></div>', 'P', ['DIV', 'SECTION']];
        case 'heading':
            return ['<div><h2' . $mark . '>', '</h2></div>', 'H2', ['DIV', 'SECTION']];
        default:
            return ['<div><div' . $mark . '>', '</div></div>', 'DIV', ['DIV', 'SECTION']];
    }
}

/**
 * The tree view: the prop parsed in its wrapper by core's HTML5 tree builder. Judges the
 * admission table per namespace, Δ1/E5, E6 ids, E11, the Δ5/P-11 gates, E10 containment
 * and the P-16 bail; collects ids and id references for E12; returns the admitted tokens
 * re-serialized (the render-side view).
 */
function _pp_content_tree_walk(string $bytes, array &$state): string {
    $nonce = bin2hex(random_bytes(8));
    [$open, $close, $container_tag, $tail] = _pp_content_wrapper($state['sink'], $nonce);
    $sentinel = 'pp-sentinel-' . $nonce;
    // The end marker is a comment the author cannot know. It must be reached INSIDE the
    // container: a prop whose closer ends the container early (`x</div>`) puts the marker
    // outside it even when the wrapper's surplus closer is silently ignored, and a prop that
    // swallows what follows (an unclosed comment, textarea or title) never reaches it.
    $end_marker = 'pp-end-' . $nonce;
    $doc = '<!DOCTYPE html><html><head></head><body><main><section>' . $open . $bytes . '<!--' . $end_marker . '-->' . $close
        . '</section><p id="' . $sentinel . '">s</p></main></body></html>';

    $p = WP_HTML_Processor::create_full_parser($doc);
    if ($p === null) {
        $state['losses'][] = _pp_content_loss('the prop', '', 'P-16', 'the HTML parser could not start');
        return '';
    }

    $phase = 'before';          // before | inside | tail | sentinel | after
    $end_seen = false;
    $container_depth = 0;
    $tail_index = 0;
    $sentinel_ok = false;
    $html = '';
    $skip_depth = null;         // depth at which a refused subtree started (render view)
    $inline = in_array($state['sink'], ['inline', 'heading'], true);
    // The open elements the PROP produced, lowercase, maintained from the token stream
    // (every pushed element yields a closer token; void, self-closed foreign and atomic
    // elements yield none and are never pushed). Depth comes from get_current_depth(), so
    // no per-token breadcrumb copy: deeply nested content stays linear.
    $stack = [];
    $state['open'] = [];
    $tree_starts = [];

    while ($p->next_token()) {
        $type  = $p->get_token_type();
        $depth = $p->get_current_depth();

        if ($phase === 'before') {
            if ($type === '#tag' && !$p->is_tag_closer() && $p->get_attribute('data-pp-sink-' . $nonce) !== null) {
                $phase = 'inside';
                $container_depth = $depth;
            }
            continue;
        }

        if ($phase === 'inside') {
            if ($type === '#comment' && $p->get_modifiable_text() === $end_marker) {
                $end_seen = true;
                continue;
            }
            // The container's own closer ends the prop.
            if ($type === '#tag' && $p->is_tag_closer() && $p->get_tag() === $container_tag
                && $depth === $container_depth - 1) {
                // The container may only close AFTER the end marker: an early close
                // (`x</div>`, `</td><td>` in a cell) or an unclosed comment that swallowed
                // the marker is E10 even where the wrapper's surplus closer is ignored.
                if (!$end_seen) {
                    $state['losses'][] = _pp_content_loss('the prop', '', 'E10',
                        'it closes its own container early (a stray closer) or leaves a comment open, so what follows renders outside it');
                    // THE WALK ENDS AT THE FIRST E10: the prop is refused whole, and what
                    // follows sits outside the container, where no depth cap applies.
                    return '';
                }
                $phase = 'tail';
                continue;
            }
            $rel = $depth - $container_depth;
            if ($type === '#tag') {
                if ($p->is_tag_closer()) {
                    $popped = array_pop($stack);
                    if ($popped !== null) {
                        $state['open'][$popped]--;
                    }
                    if ($skip_depth !== null) {
                        if ($rel < $skip_depth) {
                            $skip_depth = null;
                        }
                        continue;
                    }
                    $html .= _pp_content_serialize_closer($p);
                    continue;
                }
                // M-8, on the parser's OWN stack depth: what it keeps open (a self-closed
                // custom element) counts, and what it ignores (a closer behind a scope
                // boundary) does not. The walk stops at the first element past the cap, so
                // the tree builder never enters the region where its cost grows with the
                // square of the depth.
                if ($rel > PP_CONTENT_MAX_DEPTH) {
                    $state['losses'][] = _pp_content_loss('the prop', '', 'M-8', sprintf(
                        'it nests elements at least %d deep; a content prop may nest at most %d deep (LAYER-3-CONTRACT.md M-8), '
                        . 'because the parser\'s cost grows with the square of the depth', $rel, PP_CONTENT_MAX_DEPTH));
                    return '';
                }
                $tag = strtolower((string) $p->get_tag());
                // A VIRTUAL element (one the tree builder creates: a reconstructed formatting
                // element, an implied <tbody>) has no source token, so it never counts against
                // a lexical start tag: every counted tree start IS one lexical token, and the
                // union rule's count is exact. The HTML API reports a virtual token's
                // attributes as null (an authored token without attributes reports []).
                if ($p->get_attribute_names_with_prefix('') !== null) {
                    $start_key = _pp_content_start_key($p, $tag);
                    $tree_starts[$start_key] = ($tree_starts[$start_key] ?? 0) + 1;
                }
                $admitted = _pp_content_judge_element($p, $stack, $state, $inline);
                if ($p->expects_closer() === true) {
                    $stack[] = $tag;
                    $state['open'][$tag] = ($state['open'][$tag] ?? 0) + 1;
                }
                if ($skip_depth === null) {
                    if ($admitted === null) {
                        // A refused element emits nothing in the render view, and nor does
                        // its subtree.
                        if (!_pp_content_is_void_token($p)) {
                            $skip_depth = $rel;
                        }
                    } else {
                        $html .= $admitted;
                    }
                }
                continue;
            }
            if ($type === '#text' || $type === '#cdata-section') {
                if ($skip_depth === null) {
                    $html .= htmlspecialchars($p->get_modifiable_text(), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
                }
                continue;
            }
            // Comments, stray doctypes, funky comments and presumptuous tags paint nothing;
            // dropping them from the render view is normalisation, not a loss (§2.2).
            continue;
        }

        if ($phase === 'tail') {
            if ($type === '#tag' && $p->is_tag_closer() && $tail_index < count($tail)
                && $p->get_tag() === $tail[$tail_index]) {
                $tail_index++;
                continue;
            }
            if ($type === '#tag' && !$p->is_tag_closer() && $p->get_tag() === 'P'
                && $p->get_attribute('id') === $sentinel && $tail_index === count($tail)
                && ($p->get_breadcrumbs() ?? []) === ['HTML', 'BODY', 'MAIN', 'P']) {
                $phase = 'sentinel';
                continue;
            }
            $state['losses'][] = _pp_content_loss('the prop', '', 'E10',
                'markup escaped its container: something after it no longer sits where the template put it');
            return ''; // the first E10 ends the walk (see above)
        }

        if ($phase === 'sentinel') {
            $sentinel_ok = ($type === '#text' && $p->get_modifiable_text() === 's'
                && ($p->get_breadcrumbs() ?? []) === ['HTML', 'BODY', 'MAIN', 'P', '#text']);
            $phase = 'after';
            continue;
        }
    }

    if ($p->get_last_error() !== null) {
        $state['losses'][] = _pp_content_loss('the prop', '', 'P-16', 'unsupported markup: ' . pp_content_close_first_hint($bytes)
            . ' (the HTML parser this check uses cannot verify it, so it is refused rather than admitted unchecked)');
        return '';
    }
    if (!$sentinel_ok && !_pp_content_has_clause($state['losses'], 'E10')) {
        $state['losses'][] = _pp_content_loss('the prop', '', 'E10',
            'the next band would not render intact after it (an unclosed element swallows or re-wraps what follows)');
    }
    // THE UNION RULE, SYMMETRIC: a start tag the lexical view saw and the tree builder
    // dropped (inside <select>, a stray <td>, a nested <form>) was judged only by the
    // lexical rules, while a browser may still build it (Chromium's customizable <select>
    // keeps what WordPress 7.0's tree builder drops). It cannot be verified, so it is
    // refused. html/head/body are E4 already and are not counted twice.
    // Keyed by name AND attributes, so an element the tree builder implies (a bare
    // <tbody>, <tr>) cannot stand in for a dropped authored one that carries attributes.
    foreach ($state['lexical_starts'] as $start_key => $count) {
        $tag = strstr($start_key, ' ', true) ?: $start_key;
        if (in_array($tag, ['html', 'head', 'body'], true)) {
            continue;
        }
        if ($count > ($tree_starts[$start_key] ?? 0)) {
            $state['losses'][] = _pp_content_loss('<' . $tag . '>', '', 'P-16',
                'unsupported markup: the HTML parser this check uses drops or renames this element here (for example '
                . 'inside <select>, a table part outside a table, or <image>), while a browser may still build it as '
                . 'written, so it cannot be verified');
        }
    }
    return $html;
}

/** A start tag's identity for the lexical/tree comparison: lowercase name plus its attributes. */
function _pp_content_start_key($p, string $tag): string {
    $attrs = [];
    foreach (($p->get_attribute_names_with_prefix('') ?? []) as $name) {
        $value = $p->get_attribute($name);
        $attrs[strtolower((string) $name)] = is_string($value) ? $value : true;
    }
    if ($attrs === []) {
        return $tag;
    }
    ksort($attrs);
    return $tag . ' ' . md5(serialize($attrs));
}

/** The element path within the prop, for messages (built only when a Loss needs it). */
function _pp_content_where(array $stack, string $tag): string {
    return implode(' > ', array_merge($stack, [$tag]));
}

function _pp_content_has_clause(array $losses, string $clause): bool {
    foreach ($losses as $loss) {
        if ($loss['clause'] === $clause) {
            return true;
        }
    }
    return false;
}

function _pp_content_is_void_token(WP_HTML_Processor $p): bool {
    if ($p->get_namespace() !== 'html') {
        return $p->has_self_closing_flag();
    }
    return WP_HTML_Processor::is_void((string) $p->get_tag());
}

function _pp_content_serialize_closer(WP_HTML_Processor $p): string {
    $ns = $p->get_namespace();
    if ($ns === 'html') {
        $tag = strtolower((string) $p->get_tag());
        return WP_HTML_Processor::is_void($tag) ? '' : '</' . $tag . '>';
    }
    return '</' . $p->get_qualified_tag_name() . '>';
}

/**
 * Judges one start tag in the tree view. Returns its serialization when admitted, or null
 * when the element itself is refused (its subtree is then left out of the render view).
 * Attribute-level refusals record a Loss and leave the attribute out of the render view.
 */
function _pp_content_judge_element(WP_HTML_Processor $p, array $stack, array &$state, bool $inline): ?string {
    $ns   = $p->get_namespace();
    $raw  = (string) $p->get_tag();
    $tag  = strtolower($raw);
    $qual = $ns === 'html' ? $tag : (string) $p->get_qualified_tag_name();
    $parent = $stack === [] ? '' : (string) end($stack);
    $open = $state['open'];
    // The locator, bounded to the innermost 32 levels so deep nesting stays linear.
    $where = count($stack) > 32
        ? '… > ' . _pp_content_where(array_slice($stack, -32), $qual)
        : _pp_content_where($stack, $qual);

    // Δ1: SVG title/desc are text-only. Their element children are handed back to HTML
    // parsing (an integration point), which is how markup hid from a lexical pass.
    if (in_array($parent, ['title', 'desc'], true) && ($open['svg'] ?? 0) > 0) {
        $state['losses'][] = _pp_content_loss('<' . $qual . '> inside SVG <' . $parent . '>', $where, 'D1',
            'SVG title and desc are text-only');
        return null;
    }
    $in_metadata = ($open['metadata'] ?? 0) > 0;

    // Element admission per namespace and sink.
    if ($inline) {
        $table = _pp_content_p2_table($state['sink']);
        if ($ns !== 'html' || !isset($table[$tag])) {
            $excl = pp_content_excluded_elements()[$tag] ?? null;
            $clause = $excl ?? 'P-2';
            $reason = $excl !== null ? pp_content_clause_text($clause) : _pp_content_p2_text($state['sink']);
            if (!preg_match('/^[a-z][a-z0-9-]*\z/', $tag)) {
                $reason .= '; to show a literal "<" write &lt;';
            }
            $state['losses'][] = _pp_content_loss('<' . $qual . '>', $where, $clause, $reason);
            return null;
        }
        $element_attrs = $table[$tag];
        $globals = [];
    } elseif ($ns === 'svg') {
        if (isset(pp_content_excluded_foreign_elements()[$tag])) {
            $state['losses'][] = _pp_content_loss('<' . $qual . '> (SVG)', $where, 'E5', pp_content_clause_text('E5'));
            return null;
        }
        if (!isset(pp_content_svg_elements()[$tag]) && !($in_metadata && str_contains($tag, ':'))) {
            $state['losses'][] = _pp_content_unknown_element_loss($qual, $where);
            return null;
        }
        $element_attrs = null; // judged by the SVG attribute set below
        $globals = [];
    } elseif ($ns === 'math') {
        if (isset(pp_content_excluded_foreign_elements()[$tag])) {
            $state['losses'][] = _pp_content_loss('<' . $qual . '> (MathML)', $where, 'E5', pp_content_clause_text('E5'));
            return null;
        }
        if (!isset(pp_content_math_elements()[$tag])) {
            $state['losses'][] = _pp_content_unknown_element_loss($qual, $where);
            return null;
        }
        $element_attrs = pp_content_math_attributes();
        $globals = [];
    } else {
        $excl = pp_content_excluded_elements()[$tag] ?? null;
        if ($excl !== null && $tag !== 'object') {
            $state['losses'][] = _pp_content_loss('<' . $tag . '>', $where, $excl, pp_content_clause_text($excl));
            return null;
        }
        $table = pp_content_html_table();
        $custom = false;
        if (!isset($table[$tag])) {
            if (!pp_content_is_valid_custom_element_name($tag)) {
                $state['losses'][] = _pp_content_unknown_element_loss($tag, $where);
                return null;
            }
            // P-23: admitted with the global attributes and disclosed (plugin boundary).
            $custom = true;
            $state['notes'][] = 'custom element <' . $tag . '>';
        }
        $element_attrs = $custom ? [] : $table[$tag];
        $globals = pp_content_html_global_attributes();
    }

    $out_attrs = [];
    $attrs = $p->get_attribute_names_with_prefix('') ?? [];
    $values = [];
    foreach ($attrs as $attr) {
        $values[strtolower((string) $attr)] = $p->get_attribute($attr);
    }
    // The trust tier: a writer without unfiltered_html writes the core `post` set.
    if (($state['ctx']['tier'] ?? 'full') === 'core' && !_pp_content_core_tier_check($ns, $tag, $qual, $values, $where, $state)) {
        return null;
    }
    foreach ($values as $attr => $value) {
        $qattr = $ns === 'html' ? $attr : (string) $p->get_qualified_attribute_name($attr);
        $construct = $qattr . ' on <' . $qual . '>';
        $construct_v = static fn ($v) => sprintf('%s="%s" on <%s>', $qattr, _pp_content_reflect((string) $v, 60), $qual);
        if (_pp_content_check_universal_attribute($tag, $ns === 'svg' ? str_replace(' ', ':', $qattr) : $attr,
            $ns === 'svg' && in_array($attr, ['href', 'xlink:href'], true) ? null : $value, $where, $state)) {
            continue;
        }
        $string_value = is_string($value) ? $value : '';

        // Is the attribute admitted on this element at all?
        if ($ns === 'svg') {
            $lname = strtolower(str_replace(' ', ':', $qattr));
            if ($lname === 'href' || $lname === 'xlink:href') {
                $reason = _pp_content_svg_href_loss($tag, $string_value);
                if ($reason !== null) {
                    $state['losses'][] = _pp_content_loss($construct_v($string_value),
                        $where, $reason[0], $reason[1]);
                    continue;
                }
                $out_attrs[$lname === 'href' ? 'href' : 'xlink:href'] = $string_value;
                foreach (_pp_content_fragment_references($ns, $tag, $lname, $string_value) as $target) {
                    $state['refs'][] = ['frag:' . $lname, $qual, $target, $where, $string_value];
                }
                continue;
            }
            if ($lname === 'xml:base') {
                $state['losses'][] = _pp_content_loss($construct, $where, 'D1', 'xml:base is refused (it re-bases every URL below it)');
                continue;
            }
            $svg_attrs = pp_content_svg_attributes();
            $is_editor_ns = preg_match('/^(xmlns:)?[a-z][a-z0-9_.-]*:[a-z][a-z0-9_.-]*\z/', $lname) === 1
                && !preg_match('/^(xlink|xml):/', $lname)
                && $lname !== 'xmlns:xlink';
            if (!isset($svg_attrs[$lname]) && !$is_editor_ns && strncmp($lname, 'data-', 5) !== 0
                && !$in_metadata) {
                $state['losses'][] = _pp_content_loss($construct, $where, 'D1', 'not a static SVG attribute');
                continue;
            }
            // ONE value grammar for every SVG attribute that is not an id, class, data-* or
            // style: an editor-namespace attribute (inkscape:*, sodipodi:*, xmlns:*) is inert
            // in a browser, and runs the same Δ1 gate anyway, CSS unescaping included.
            if ($lname !== 'style' && $lname !== 'id' && $lname !== 'class' && strncmp($lname, 'data-', 5) !== 0
                && is_string($value)
                && ($reason = pp_content_svg_value_loss($lname, $value)) !== null) {
                $state['losses'][] = _pp_content_loss($construct_v($value),
                    $where, 'D1', $reason);
                continue;
            }
            $out_attrs[$svg_attrs[$lname] ?? $lname] = $string_value;
        } else {
            $is_data = strncmp($attr, 'data-', 5) === 0 && preg_match('/^data-[^\s"\'>\/=]+\z/', $attr) === 1;
            $allowed = isset($element_attrs[$attr]) || isset($globals[$attr]) || ($is_data && !$inline);
            if (!$allowed) {
                if ($attr === 'nonce') {
                    $state['losses'][] = _pp_content_loss($construct, $where, 'P-17',
                        'nonce only affects script, style and link, which are refused (E4), so here it would do nothing');
                } elseif ($inline) {
                    $state['losses'][] = _pp_content_loss($construct, $where, 'P-2', _pp_content_p2_text($state['sink']));
                } else {
                    $state['losses'][] = _pp_content_loss($construct, $where, 'P-17', pp_content_clause_text('P-17'));
                }
                continue;
            }
            $reason = $ns === 'math' ? _pp_content_math_value_loss($attr, $string_value) : _pp_content_html_value_loss($tag, $attr, $string_value);
            if ($reason !== null) {
                $state['losses'][] = _pp_content_loss($construct_v($string_value),
                    $where, $reason[0], $reason[1]);
                continue;
            }
            $out_attrs[$attr] = $value;
        }

        // id / name: E6, E11, collection for E12.
        $lower = strtolower(str_replace(' ', ':', $qattr));
        if ($lower === 'id' && is_string($value)) {
            $reason = _pp_content_id_loss($value, $state['ctx']);
            if ($reason !== null) {
                $state['losses'][] = _pp_content_loss('id="' . _pp_content_reflect($value, 64) . '" on <' . $qual . '>',
                    $where, $reason[0], $reason[1]);
                unset($out_attrs[$qattr], $out_attrs['id']);
                continue;
            }
            if ($value !== '') {
                $state['ids'][] = $value;
            }
        }
        // An empty name joins no group and names no map (HTML), so it is no fact.
        if ($lower === 'name' && $ns === 'html' && is_string($value) && $value !== '') {
            if ($tag === 'map') {
                $state['map_names'][] = $value;
            }
            if ($tag === 'details') {
                $state['details_names'][] = [$value, $where];
            }
        }
        if (isset(pp_content_idref_attributes()[$attr]) && is_string($value)) {
            $state['refs'][] = [$attr, $tag, $value, $where];
        }
        if ($ns === 'html' && $attr === 'for' && $tag === 'label' && is_string($value)) {
            $state['refs'][] = ['for:one', $tag, $value, $where];
        }
        if ($ns === 'html' && $attr === 'usemap' && is_string($value)) {
            $state['refs'][] = ['usemap', $tag, $value, $where];
        }
        // SVG same-document fragment references (routed item 2, ruled): `<use href="#x">`
        // and the other fragment-href elements, `url(#x)` in an SVG reference attribute, and
        // `url(#x)` in any style attribute (P-13). Each binds to the FIRST element with that
        // id in the document, so it is an E12 reference like any other.
        if (is_string($value)) {
            foreach (_pp_content_fragment_references($ns, $tag, $lower, $value) as $target) {
                $state['refs'][] = ['frag:' . $qattr, $qual, $target, $where, $value];
            }
        }
    }

    if ($ns === 'html') {
        _pp_content_clobber_losses($p, $tag, $values, $where, $state);
        // Element-level gates that need the whole attribute set.
        $element_reason = _pp_content_html_element_loss($tag, $values);
        if ($element_reason !== null) {
            $state['losses'][] = _pp_content_loss('<' . $tag . '>', $where, $element_reason[0], $element_reason[1]);
            return null;
        }
        if (isset($values['is']) && is_string($values['is'])) {
            $state['notes'][] = 'customized built-in <' . $tag . ' is="' . _pp_content_reflect($values['is'], 64) . '">';
        }
        // Δ4: a link that opens another browsing context carries rel=noopener (render
        // view only; disclosed as normalisation, M-10).
        if (in_array($tag, ['a', 'area'], true) && isset($out_attrs['target'])
            && is_string($out_attrs['target'])
            && !in_array(strtolower(trim($out_attrs['target'])), ['', '_self', '_parent', '_top'], true)) {
            $rel = isset($out_attrs['rel']) && is_string($out_attrs['rel']) ? $out_attrs['rel'] : '';
            if (!in_array('noopener', _pp_content_split_ws(strtolower($rel)), true)) {
                $out_attrs['rel'] = trim($rel . ' noopener');
            }
        }
    } elseif ($ns === 'svg' && $tag === 'a' && isset($out_attrs['target']) && is_string($out_attrs['target'])
        && !in_array(strtolower(trim($out_attrs['target'])), ['', '_self', '_parent', '_top'], true)) {
        $rel = isset($out_attrs['rel']) && is_string($out_attrs['rel']) ? $out_attrs['rel'] : '';
        if (!in_array('noopener', _pp_content_split_ws(strtolower($rel)), true)) {
            $out_attrs['rel'] = trim($rel . ' noopener');
        }
    }

    $s = '<' . $qual;
    foreach ($out_attrs as $name => $value) {
        $s .= ' ' . $name;
        if ($value !== true) {
            $s .= '="' . htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8') . '"';
        }
    }
    if ($ns !== 'html' && $p->has_self_closing_flag()) {
        return $s . '/>';
    }
    return $s . '>';
}

/**
 * E11 on the real named-access surface (pp_content_clobber_table()), judged on the tree:
 *   - a `name` document named access exposes, on the elements it exposes it on;
 *   - an `id` on an <object> (document named access exposes an object by id as well).
 * Form-control named access needs a form, and forms are descoped (Δ5, D5): refused.
 */
function _pp_content_clobber_losses(WP_HTML_Processor $p, string $tag, array $values, string $where, array &$state): void {
    $clobber = pp_content_clobber_table();
    $name = isset($values['name']) && is_string($values['name']) ? $values['name'] : null;
    $id   = isset($values['id']) && is_string($values['id']) ? $values['id'] : null;
    $document = static function (string $attr, string $v) use ($tag, $where, &$state): void {
        $state['losses'][] = _pp_content_loss($attr . '="' . _pp_content_reflect($v, 64) . '" on <' . $tag . '>', $where, 'E11',
            sprintf('this %s shadows document.%s (document named access)', $attr, _pp_content_reflect($v, 64)));
    };
    if ($name !== null && isset(pp_content_named_access_elements()[$tag]) && isset($clobber['document'][$name])) {
        $document('name', $name);
    }
    if ($id !== null && $tag === 'object' && isset($clobber['document'][$id])) {
        $document('id', $id);
    }
}

/**
 * The ids an admitted attribute value refers to by same-document fragment: an SVG
 * fragment-href element's `href`/`xlink:href` (`#x`), an SVG reference attribute's
 * `url(#x)`, and every `url(#x)` in a style attribute.
 *
 * @return list<string>
 */
function _pp_content_fragment_references(string $ns, string $tag, string $attr, string $value): array {
    $url = '/url\(\s*(["\']?)#([A-Za-z_][A-Za-z0-9_.\-]{0,63})\1\s*\)/i';
    if ($attr === 'style') {
        return preg_match_all($url, $value, $m) ? $m[2] : [];
    }
    if ($ns !== 'svg') {
        return [];
    }
    if (($attr === 'href' || $attr === 'xlink:href') && isset(pp_content_svg_fragment_href_elements()[$tag])
        && preg_match('/^#([A-Za-z_][A-Za-z0-9_.\-]{0,63})\z/', $value, $m)) {
        return [$m[1]];
    }
    if (isset(pp_content_svg_reference_attributes()[$attr]) && preg_match($url, $value, $m)) {
        return [$m[2]];
    }
    return [];
}

/** E5 / P-12 / Δ1: `href` and `xlink:href` on an SVG element. Returns [clause, reason] or null. */
function _pp_content_svg_href_loss(string $tag, string $value): ?array {
    if ($tag === 'a') {
        $r = pp_content_url_loss($value, 'link');
        return $r === null ? null : ['E2', $r];
    }
    if (isset(pp_content_svg_fragment_href_elements()[$tag])) {
        return preg_match('/^#[A-Za-z_][A-Za-z0-9_.\-]{0,63}\z/', $value)
            ? null
            : ['D1', 'only a same-document fragment (#id) is admitted here (P-12)'];
    }
    return ['E5', 'href is refused on this SVG element'];
}

/**
 * MathML attribute values (mathsize, mathcolor, mathbackground …) are CSS values to a browser:
 * the same default-deny function list and the no-URL rule as SVG values, except on text
 * attributes (orchestrator ruling, 2026-10-05). Returns [clause, reason] or null.
 */
function _pp_content_math_value_loss(string $attr, string $value): ?array {
    // Namespace parity with SVG: ids, classes, data-* and style (judged as a style attribute)
    // are not CSS values.
    if (_pp_content_is_text_attribute($attr) || in_array($attr, ['id', 'class', 'style'], true)
        || strncmp($attr, 'data-', 5) === 0) {
        return null;
    }
    // The SVG gate's pre-refusals, so a comment cannot pair the quotes the function check
    // skips (`/*"*/calc(...)/*"*/`) and nothing escapes the value.
    if (preg_match('/[\x00-\x08\x0B\x0E-\x1F\x7F<>{};\\\\]/', $value) || strpos($value, '/*') !== false || strpos($value, '*/') !== false) {
        return ['E5', 'MathML attribute values may not contain control characters, < > { } ; \\ or comment delimiters'];
    }
    // No separate no-URL test: url(), image(), image-set() and src() are not on the
    // admitted function list, so the function check below refuses them.
    $function = _pp_content_unlisted_css_function($value);
    return $function === null ? null
        : ['E5', sprintf('%s() is not on the admitted CSS function list (unknown functions are refused)', _pp_content_reflect($function, 40))];
}

/** Value gates for an admitted HTML attribute. Returns [clause, reason] or null. */
function _pp_content_html_value_loss(string $tag, string $attr, string $value): ?array {
    if ($attr === 'is') {
        return pp_content_is_valid_custom_element_name(strtolower($value))
            ? null
            : ['P-17', 'is must name a valid custom element that does not shadow an HTML, SVG or MathML element'];
    }
    if ($tag === 'object' && $attr === 'data') {
        return pp_content_is_same_install_pdf($value) ? null
            : ['E3', 'an <object> is admitted only for a PDF in this site\'s uploads (P-11)'];
    }
    return null;
}

/** Element gates that need every attribute at once. Returns [clause, reason] or null. */
function _pp_content_html_element_loss(string $tag, array $values): ?array {
    if ($tag === 'object') {
        $type = isset($values['type']) && is_string($values['type']) ? strtolower(trim($values['type'])) : '';
        $data = isset($values['data']) && is_string($values['data']) ? $values['data'] : '';
        if ($type !== 'application/pdf' || !pp_content_is_same_install_pdf($data)) {
            return ['E3', 'an <object> is admitted only as type="application/pdf" with data in this site\'s uploads (P-11)'];
        }
    }
    return null;
}

/**
 * P-11: a PDF in this install's uploads, judged the way core's _wp_kses_allow_pdf_objects()
 * judges it (no query or fragment, a .pdf path) but anchored to the uploads base URL
 * rather than only its host.
 */
function pp_content_is_same_install_pdf(string $url): bool {
    if (!function_exists('wp_upload_dir')) {
        return false;
    }
    $uploads = wp_upload_dir(null, false);
    $baseurl = is_array($uploads) && isset($uploads['baseurl']) && is_string($uploads['baseurl']) ? $uploads['baseurl'] : '';
    // Both read by the shared canonicaliser: dot segments in every spelling are resolved
    // before the prefix comparison, and what it cannot parse is not this install's PDF.
    $c = pp_content_url_parse($url);
    $base = $baseurl === '' ? null : pp_content_url_parse($baseurl);
    if ($c === null || $base === null || $base['kind'] !== 'absolute' || !in_array($base['scheme'], ['http', 'https'], true)
        || $c['query'] !== null || $c['fragment'] !== null || !preg_match('/\.pdf\z/i', $c['path'])) {
        return false;
    }
    // The canonical ORIGIN must equal the uploads base's: scheme, host and port (owner
    // ruling, 2026-10-05). A root-relative path resolves against the page, so the SITE's
    // origin is the one compared; a network-path reference takes the page's scheme. http
    // against an https base is another origin (mixed content), and so is another port. A
    // host the canonicaliser keeps as written (a non-ASCII spelling a browser would map, a
    // trailing dot) never equals the base's: inequality is refusal.
    $site = _pp_content_site_url_parts();
    if ($c['kind'] === 'relative') {
        // Only a root-relative path names a fixed place; a path relative to the page does not.
        if (!str_starts_with($c['path'], '/')) {
            return false;
        }
        $origin = [$site['scheme'], $site['host'], $site['port']];
    } elseif (in_array($c['kind'], ['absolute', 'network'], true)) {
        $origin = [$c['scheme'] ?? $site['scheme'], $c['host'], $c['port']];
    } else {
        return false;
    }
    if ($origin !== [$base['scheme'], $base['host'], $base['port']]) {
        return false;
    }
    $prefix = rtrim($base['path'], '/') . '/';
    if ($prefix === '/' || !str_starts_with($c['path'], $prefix)) {
        return false;
    }
    // After the uploads base: no climbing out in a spelling a server decodes (a percent-
    // encoded dot segment or backslash).
    $rest = substr($c['path'], strlen($prefix));
    for ($i = 0; $i < 3 && preg_match('/%[0-9a-f]{2}/i', $rest); $i++) {
        $rest = rawurldecode($rest);
    }
    return strpos($rest, '\\') === false && !preg_match('#(^|/)\.{1,2}(/|\z)#', $rest);
}

/** E6 for an authored id (E11 is _pp_content_clobber_losses()). Returns [clause, reason] or null. */
function _pp_content_id_loss(string $id, array $ctx): ?array {
    if (preg_match('/^(pp|it)-[0-9a-f]{8}\z/', $id) || in_array($id, ['main', 'pp-nav-menu'], true)) {
        return ['E6', 'this id has the form the engine mints or reserves'];
    }
    if (isset($ctx['anchors'][$id])) {
        return ['E6', 'this id equals a band anchor (props.id) on this page'];
    }
    return null;
}

/**
 * The cross-band context in its working form: keyed sets and page-wide counts, built once
 * per band by pp_content_band_context(). A hand-made context of plain lists (a direct
 * caller, a test) is converted here once per call.
 */
function _pp_content_ctx(array $ctx): array {
    if (isset($ctx['_sets'])) {
        return $ctx;
    }
    $set = static fn ($list) => array_fill_keys(array_map('strval', (array) $list), 1);
    return [
        '_sets'     => true,
        'anchors'   => $set($ctx['anchors'] ?? []),
        'band_ids'  => $set($ctx['band_ids'] ?? []),
        'band_maps' => $set($ctx['band_map_names'] ?? []),
        'counts'    => [
            'ids'     => $set($ctx['other_ids'] ?? []),
            'details' => $set($ctx['other_details_names'] ?? []),
            'refs'    => $set($ctx['other_refs'] ?? []),
            'maps'    => $set($ctx['other_map_names'] ?? []),
        ],
        'own'       => ['ids' => [], 'details' => [], 'refs' => [], 'maps' => []],
        'incomplete' => false,
        // Fail closed: only an explicit 'full' (or no tier at all: a caller outside the write
        // path) is the full tier; any other value is core parity.
        'tier'      => ($ctx['tier'] ?? 'full') === 'full' ? 'full' : 'core',
    ];
}

/** Does some OTHER band carry this id / details name / reference target? */
function _pp_content_in_other(array $ctx, string $kind, string $value): bool {
    return ($ctx['counts'][$kind][$value] ?? 0) > ($ctx['own'][$kind][$value] ?? 0);
}

/** E12: every id reference must resolve inside the same band, and only there. */
function _pp_content_resolve_references(array &$state): void {
    $ctx = $state['ctx'];
    $own_ids = array_fill_keys(array_map('strval', $state['ids']), true);
    $maps = array_fill_keys(array_map('strval', $state['map_names']), true);
    $kinds = pp_content_idref_attributes();
    foreach ($state['refs'] as $ref) {
        [$attr, $tag, $value, $where] = $ref;
        $shown = $ref[4] ?? $value; // a fragment reference shows the attribute's own value
        if ($attr === 'usemap') {
            // A browser binds `#m` to the FIRST <map> in the document whose name or id is
            // `m`, so the map must be in this band and no other band may carry that name or id.
            $name = substr($value, 1);
            if ($value === '' || $value[0] !== '#' || !(isset($maps[$name]) || isset($ctx['band_maps'][$name]))
                || _pp_content_in_other($ctx, 'maps', $name) || _pp_content_in_other($ctx, 'ids', $name)) {
                $state['losses'][] = _pp_content_loss('usemap="' . _pp_content_reflect($value, 64) . '" on <' . $tag . '>',
                    $where, 'E12', 'usemap must name a <map name> inside the same band, and no other band may carry that name or id');
            }
            continue;
        }
        $many = ($kinds[$attr] ?? '') === 'many' || $attr === 'for:many';
        // Matched as the browser matches: a list split on HTML whitespace, a single
        // reference whole (a whitespace-padded one is refused earlier). An SVG fragment
        // reference (`frag:` — `<use href="#x">`, `url(#x)`; routed item 2, ruled) names one id.
        $targets = $many ? _pp_content_split_ws($value) : [$value];
        $label = str_starts_with($attr, 'frag:') ? substr($attr, 5) : str_replace(['for:one', 'for:many'], 'for', $attr);
        foreach ($targets as $target) {
            // In band, and ONLY in band: an id that another band also carries binds to
            // whichever comes first in the document, so the reference may land outside.
            if ($target === '' || !(isset($own_ids[$target]) || isset($ctx['band_ids'][$target]))
                || _pp_content_in_other($ctx, 'ids', $target)) {
                $state['losses'][] = _pp_content_loss($label . '="' . _pp_content_reflect($shown, 64) . '" on <' . $tag . '>',
                    $where, 'E12', pp_content_clause_text('E12'));
                break;
            }
        }
    }
    // The other direction: an id this band authors that ANOTHER band already refers to
    // would capture that band's control when it comes first in the document. The band
    // being written is the one refused (the #1007 rule), as with the anchor-add rule.
    foreach (array_keys($own_ids) as $id) {
        if (_pp_content_in_other($ctx, 'refs', (string) $id)) {
            $state['losses'][] = _pp_content_loss('id="' . _pp_content_reflect((string) $id, 64) . '"', '', 'E12',
                'another band refers to this id, so its control would bind to this element');
        }
    }
    foreach (array_keys($maps) as $name) {
        if (_pp_content_in_other($ctx, 'refs', (string) $name)) {
            $state['losses'][] = _pp_content_loss('name="' . _pp_content_reflect((string) $name, 64) . '" on <map>', '', 'E12',
                'another band\'s usemap names this map, so its image would bind to this map');
        }
    }
    foreach ($state['details_names'] as [$name, $where]) {
        if (_pp_content_in_other($ctx, 'details', (string) $name)) {
            $state['losses'][] = _pp_content_loss('name="' . _pp_content_reflect($name, 64) . '" on <details>',
                $where, 'E12', 'a details name group may not join details in another band');
        }
    }
    // Uncertainty is not admission: when another band's facts could not be read within the
    // write's budget, a cross-band fact in this prop cannot be verified against them.
    if (!empty($ctx['incomplete'])) {
        $facts = array_merge(
            array_map(static fn ($id) => 'id="' . _pp_content_reflect((string) $id, 64) . '"', array_keys($own_ids)),
            array_map(static fn ($r) => preg_replace('/^(frag:|for:one|for:many)/', '', $r[0]) . '="' . _pp_content_reflect((string) $r[2], 64) . '"',
                $state['refs']),
            array_map(static fn ($d) => 'name="' . _pp_content_reflect((string) $d[0], 64) . '" on <details>', $state['details_names']),
            array_map(static fn ($m) => 'name="' . _pp_content_reflect((string) $m, 64) . '" on <map>', array_keys($maps))
        );
        if ($facts !== []) {
            $state['losses'][] = _pp_content_loss($facts[0], '', 'E12', sprintf(
                'the page\'s other bands hold more content than one write checks for cross-band ids (%d bytes), so an id, '
                . 'id reference or details group here cannot be verified against them; send this content without it, or '
                . 'make the page smaller', PP_CONTENT_WRITE_MAX_BYTES));
        }
    }
}

/**
 * P-16's message: which element to close first. A lexical walk of the source finds the
 * first closer that does not match the innermost open element, or the innermost element
 * still open at the end.
 */
function pp_content_close_first_hint(string $bytes): string {
    $p = new WP_HTML_Tag_Processor($bytes);
    $stack = [];
    while ($p->next_token()) {
        if ($p->get_token_type() !== '#tag') {
            continue;
        }
        $tag = strtolower((string) $p->get_tag());
        if (!$p->is_tag_closer()) {
            // A self-closing flag closes only foreign (SVG/MathML) elements; on an HTML
            // element like `<i class="icon"/>` it is ignored and the element stays open.
            // A hyphenated custom element is an HTML element, so its flag is ignored too.
            if (WP_HTML_Processor::is_void($tag)
                || ($p->has_self_closing_flag() && !isset(pp_content_html_table()[$tag])
                    && !pp_content_is_valid_custom_element_name($tag))) {
                continue;
            }
            // <p> and <li> close an open sibling of the same name by themselves.
            if (($tag === 'p' || $tag === 'li') && end($stack) === $tag) {
                array_pop($stack);
            }
            $stack[] = $tag;
            continue;
        }
        if (end($stack) === $tag) {
            array_pop($stack);
            continue;
        }
        if (in_array($tag, $stack, true)) {
            $inner = end($stack);
            return sprintf('close <%s> before </%s>', $inner, $tag);
        }
    }
    $formatting = ['a', 'b', 'big', 'code', 'em', 'font', 'i', 'nobr', 's', 'small', 'strike', 'strong', 'tt', 'u'];
    for ($i = count($stack) - 1; $i >= 0; $i--) {
        if (in_array($stack[$i], $formatting, true)) {
            return sprintf('close <%s> (it is still open where its container ends)', $stack[$i]);
        }
    }
    return 'close every element in the order it was opened (a link may not contain another link, and text directly inside a <table> must sit in a cell)';
}

// ── BAND LEVEL: what the write gate asks ─────────────────────────────────────────────
//
// Everything below works from ONE index per composition (pp_content_composition_index()):
// each band's content values and the ids, references, map names and details names they
// carry. The cross-band rules (E6 anchors, E12, the anchor-add refusal) and every band's
// context are derived from it, so a page's cost is linear in its bands rather than
// quadratic (measured before the index: 50 bands with a <details> each took ~5 s in
// context building alone).
//
// ONE fact extractor (cycle-8 ruling): every band's facts, judged or not, are read by the
// predicate's own walk (_pp_content_check()), so the facts the cross-band rules use are
// the elements the parser builds, whichever band carries them. A judged band's walk is kept
// and finished (never walked twice). Unchanged bands are walked under the write's budget
// (PP_CONTENT_WRITE_MAX_BYTES, less what the write judges), so a large stored page cannot
// make a write slow; a band past the budget is marked INCOMPLETE, and a write whose content
// carries a cross-band fact (an id, an id reference, a details group) or adds an anchor on
// such a page is refused, since what it would collide with cannot be verified.

/**
 * The facts the cross-band rules need from one band, read by the predicate's walk: its
 * content values, and the ids, map names, details names and id-reference targets those
 * carry.
 *
 * @param  array    $item     The band.
 * @param  array    $anchors  Every band anchor of the composition (the walk's E6 check).
 * @param  bool     $keep     Keep the walk states (`states`, by value position) for
 *                            pp_content_band_losses() to finish: a band the write judges.
 * @param  int|null $budget   Bytes this band may still walk (decremented); null: unbounded.
 *                            A value that does not fit is not walked and the band is
 *                            `incomplete`.
 * @param  string   $tier     The writer's trust tier for a judged band (pp_content_write_tier()).
 * @param  int|null $walks    Values this band may still walk (decremented), with $budget.
 * @return array{values:list<array>, ids:list<string>, maps:list<string>, details:list<string>, refs:list<string>, anchor:string, incomplete:bool, states?:array}
 */
function pp_content_band_facts(array $item, array $anchors = [], bool $keep = true, ?int &$budget = null, string $tier = 'full', ?int &$walks = null): array {
    $values = pp_content_band_values($item);
    $anchor = (isset($item['props']['id']) && is_string($item['props']['id'])) ? $item['props']['id'] : '';
    $ids = $maps = $details = $refs = [];
    $states = [];
    $incomplete = false;
    foreach ($values as $n => [, , $sink, $value]) {
        // A value with no `<` has no element and one with no `=` has no attribute value, so
        // neither carries a fact. A value over the prop cap is not read (the write refuses
        // it, and a stored one is never judged; #1251 records the legacy gap this leaves).
        if (!$keep && (strpos($value, '<') === false || strpos($value, '=') === false
            || strlen($value) > PP_CONTENT_PROP_MAX_BYTES)) {
            continue;
        }
        // Bounded in BYTES and in WALKS: each walk has a fixed cost (one wrapper parse).
        if ($budget !== null) {
            if (strlen($value) > $budget || ($walks !== null && $walks < 1)) {
                $incomplete = true;
                continue;
            }
            $budget -= strlen($value);
            if ($walks !== null) {
                $walks--;
            }
        }
        // A band read only for its facts is walked without the anchors: an id equal to an
        // anchor is exactly the fact the anchor-add rule needs (E6 would drop it).
        $state = _pp_content_check($value, $sink, $keep ? ['anchors' => $anchors, 'tier' => $tier] : []);
        if ($keep) {
            $states[$n] = $state;
        }
        if (isset($state['final'])) {
            continue;
        }
        array_push($ids, ...array_map('strval', $state['ids']));
        array_push($maps, ...array_map('strval', $state['map_names']));
        array_push($details, ...array_map(static fn ($d) => (string) $d[0], $state['details_names']));
        foreach ($state['refs'] as [$attr, , $v]) {
            if ($attr === 'usemap') {
                // A browser binds a usemap to what follows its FIRST `#` (a stored value
                // need not start with one); with no `#` it binds to nothing.
                $hash = strpos((string) $v, '#');
                if ($hash !== false) {
                    $refs[] = substr((string) $v, $hash + 1);
                }
            } elseif (str_starts_with($attr, 'frag:')) {
                $refs[] = (string) $v;
            } else {
                array_push($refs, ..._pp_content_split_ws((string) $v));
            }
        }
    }
    $facts = ['values' => $values, 'ids' => $ids, 'maps' => $maps, 'details' => $details,
        'refs' => $refs, 'anchor' => $anchor, 'incomplete' => $incomplete];
    if ($keep) {
        $facts['states'] = $states;
    }
    return $facts;
}

/**
 * One facts record per band of a composition (null for a non-array band).
 *
 * @param array      $items   The composition.
 * @param array|null $judged  The keys of the bands this write judges: walked first, their
 *                            walks kept, their bytes charged to the budget. The other bands
 *                            are walked for facts with what remains of it. Null judges every
 *                            band (unbounded: a direct caller, a test).
 * @param string     $tier    The writer's trust tier the judged bands are walked under.
 */
function pp_content_composition_index(array $items, ?array $judged = null, string $tier = 'full'): array {
    $anchors = [];
    foreach ($items as $item) {
        if (is_array($item) && isset($item['props']['id']) && is_string($item['props']['id']) && $item['props']['id'] !== '') {
            $anchors[] = $item['props']['id'];
        }
    }
    $index = [];
    if ($judged === null) {
        foreach ($items as $key => $item) {
            $index[$key] = is_array($item) ? pp_content_band_facts($item, $anchors) : null;
        }
        return $index;
    }
    $judged_set = array_fill_keys(array_map('strval', $judged), true);
    // One budget for everything the write walks, in bytes and in values (the M-8 write
    // caps): the judged bands first, then the unchanged bands' facts with what remains.
    $budget = PP_CONTENT_WRITE_MAX_BYTES;
    $walks = PP_CONTENT_WRITE_MAX_VALUES;
    $unbounded = null;
    foreach ($items as $key => $item) {
        if (is_array($item) && isset($judged_set[(string) $key])) {
            $index[$key] = pp_content_band_facts($item, $anchors, true, $unbounded, $tier);
            $budget -= array_sum(array_map(static fn ($v) => strlen($v[3]), $index[$key]['values']));
            $walks -= count($index[$key]['values']);
        }
    }
    $budget = max(0, $budget);
    $walks = max(0, $walks);
    foreach ($items as $key => $item) {
        if (!array_key_exists($key, $index)) {
            $index[$key] = is_array($item) ? pp_content_band_facts($item, $anchors, false, $budget, 'full', $walks) : null;
        }
    }
    // Keep the composition's own order (band_context and the anchor pass iterate it).
    $ordered = [];
    foreach (array_keys($items) as $key) {
        $ordered[$key] = $index[$key];
    }
    return $ordered;
}

/**
 * Page-wide counts over an index, built ONCE per write: every id (content ids and band
 * anchors), details name and reference target, with how many times it occurs. A band's
 * "other bands" view is then a subtraction, never a per-band rebuild of the page's sets
 * (measured before: a write's cost grew with changed bands × every id on the page).
 */
function pp_content_index_counts(array $index): array {
    $counts = ['ids' => [], 'details' => [], 'refs' => [], 'maps' => [], 'anchors' => [], 'incomplete' => false];
    foreach ($index as $facts) {
        if ($facts === null) {
            continue;
        }
        $counts['incomplete'] = $counts['incomplete'] || !empty($facts['incomplete']);
        foreach (_pp_content_own_counts($facts) as $kind => $own) {
            foreach ($own as $value => $n) {
                $counts[$kind][$value] = ($counts[$kind][$value] ?? 0) + $n;
            }
        }
        if ($facts['anchor'] !== '') {
            $counts['anchors'][$facts['anchor']] = ($counts['anchors'][$facts['anchor']] ?? 0) + 1;
        }
    }
    return $counts;
}

/** One band's own counts, in the shape pp_content_index_counts() sums. */
function _pp_content_own_counts(array $facts): array {
    $tally = static function (array $list): array {
        $out = [];
        foreach ($list as $v) {
            $out[(string) $v] = ($out[(string) $v] ?? 0) + 1;
        }
        return $out;
    };
    $ids = $facts['ids'];
    if ($facts['anchor'] !== '') {
        $ids[] = $facts['anchor'];
    }
    return ['ids' => $tally($ids), 'details' => $tally($facts['details']), 'refs' => $tally($facts['refs']),
        'maps' => $tally($facts['maps'])];
}

/**
 * The cross-band facts the predicate needs for one band (§2.1 CompositionContext), as
 * keyed sets plus the page-wide counts (pp_content_index_counts()).
 *
 * @param array      $items   The composition the band will live in.
 * @param int|string $key     The band's key in $items.
 * @param array|null $index   pp_content_composition_index($items), when the caller has it.
 * @param array|null $counts  pp_content_index_counts($index), when the caller has it.
 */
function pp_content_band_context(array $items, $key, ?array $index = null, ?array $counts = null): array {
    $index ??= pp_content_composition_index($items);
    $counts ??= pp_content_index_counts($index);
    $own = $index[$key] ?? null;
    $own_counts = $own === null ? ['ids' => [], 'details' => [], 'refs' => [], 'maps' => []] : _pp_content_own_counts($own);
    return [
        '_sets'     => true,
        // A band's own anchor is in band for E12, and still reserved against content ids (E6).
        'anchors'   => $counts['anchors'],
        'band_ids'  => $own_counts['ids'],
        'band_maps' => $own === null ? [] : array_fill_keys($own['maps'], 1),
        'counts'    => $counts,
        'own'       => $own_counts,
        // Some other band's facts could not be read within the write's budget.
        'incomplete' => !empty($counts['incomplete']),
    ];
}

/**
 * Every Loss in one band's content props, each tagged with the prop it came from.
 *
 * @return list<array{prop:string, loss:array}>
 */
function pp_content_band_losses(array $items, $key, ?array $index = null, ?array $counts = null): array {
    if (!is_array($items[$key] ?? null)) {
        return [];
    }
    $index ??= pp_content_composition_index($items);
    $values = $index[$key]['values'] ?? [];
    if ($values === []) {
        return [];
    }
    $ctx = pp_content_band_context($items, $key, $index, $counts);
    $states = $index[$key]['states'] ?? null;
    $out = [];
    foreach ($values as $n => [$label, , $sink, $value]) {
        // The walk that produced this band's facts is finished, never run twice.
        $result = isset($states[$n]) ? _pp_content_finish($states[$n], $ctx) : pp_content_sanitize($value, $sink, $ctx);
        foreach ($result['losses'] as $loss) {
            $out[] = ['prop' => $label, 'loss' => $loss];
        }
    }
    return $out;
}

/**
 * The stored composition a write is compared against (§2.6). A row stored in the wrong
 * shape (an object keyed by position, `unexpected_shape`) still holds the bands the editor's
 * repair save re-sends, so its bands are the baseline; an empty one would make every band
 * "new" and block the repair on legacy content. An undecodable row has no bands.
 */
function pp_content_stored_baseline(int $post_id): array {
    $result = pp_get_composition_result($post_id);
    if (!empty($result['ok'])) {
        return (array) $result['composition'];
    }
    if (($result['error'] ?? null) === 'unexpected_shape' && is_string($result['raw'] ?? null)) {
        $decoded = json_decode($result['raw'], true);
        if (is_array($decoded)) {
            return array_values(array_filter($decoded, 'is_array'));
        }
    }
    return [];
}

/** A band's content values keyed by their message label. */
function _pp_content_value_map(array $item): array {
    $map = [];
    foreach (pp_content_band_values($item) as [$label, , , $value]) {
        $map[$label] = $value;
    }
    return $map;
}

/** A content value map's structural key (§2.2's parsed-structure comparison). */
function _pp_content_structural_key(array $map): string {
    return md5(serialize(array_map('pp_content_structural_signature', $map)));
}

/**
 * §2.6 / M-2 (ruled Q-A4; one-to-one per the 2026-10-05 ruling on T3a's 7A question 1):
 * which incoming bands are UNCHANGED, i.e. carry the content of a DISTINCT stored band of
 * the same component. Compared on RAW parses (never on sanitized output), so
 * `onclick="a"` → `onclick="b"` is a change; byte equality is the fast path; an id-less band
 * re-sent under a fresh id still matches by content.
 *
 * ONE-TO-ONE. Each stored band vouches for at most one incoming band. A second copy of a
 * stored band is new content and passes the gate: otherwise `update_composition` sending a
 * legacy band twice, or `update_component` turning band B into a copy of band A, would put
 * content on the page that no write ever judged (a copied `<details name>` group creates a
 * cross-band E12 fact the stored page never had).
 *
 * Matching order, so the obvious pairing wins: the stored band at the same position, then
 * the stored band with the same band id, then any unused stored band byte-equal, then any
 * unused stored band structurally equal. All incoming bands take part, also on a
 * band-scoped write, so the band the caller did not touch claims its own stored band first.
 *
 * @param  array $items   The composition being written.
 * @param  array $stored  The stored composition.
 * @return array<int|string, true>  The keys of $items that are unchanged.
 */
function pp_content_unchanged_keys(array $items, array $stored): array {
    $s = [];
    foreach (array_values($stored) as $n => $band) {
        if (is_array($band) && is_string($band['component'] ?? null)) {
            $map = _pp_content_value_map($band);
            $s[$n] = ['component' => $band['component'], 'map' => $map, 'bytes' => md5(serialize($map)),
                'id' => is_scalar($band['id'] ?? null) ? (string) $band['id'] : null, 'sig' => null];
        }
    }
    $in = [];
    $position = 0;
    foreach ($items as $key => $band) {
        if (is_array($band) && is_string($band['component'] ?? null)) {
            $map = _pp_content_value_map($band);
            $in[$key] = ['component' => $band['component'], 'map' => $map, 'bytes' => md5(serialize($map)),
                'id' => is_scalar($band['id'] ?? null) ? (string) $band['id'] : null, 'sig' => null, 'pos' => $position];
        }
        $position++;
    }
    $used = [];
    $out = [];
    $claim = static function ($key, int $n) use (&$used, &$out): void {
        $used[$n] = true;
        $out[$key] = true;
    };
    // 1. same position, byte-equal
    foreach ($in as $key => $b) {
        $n = $b['pos'];
        if (isset($s[$n]) && !isset($used[$n]) && $s[$n]['component'] === $b['component'] && $s[$n]['bytes'] === $b['bytes']) {
            $claim($key, $n);
        }
    }
    // Passes 2 to 4 look stored bands up in BUCKETS (by id and bytes, by bytes, by shape and
    // structural key), never pair by pair: their cost is linear in the bands, whatever their
    // number (pair loops measured 18 s for 12,000 tiny bands).
    // A bucket is a FIFO list read through a cursor (array_shift would re-index it on every
    // take, quadratic in a large bucket).
    $take = static function (array &$bucket) use (&$used): ?int {
        while ($bucket['at'] < count($bucket['list'])) {
            $n = $bucket['list'][$bucket['at']++];
            if (!isset($used[$n])) {
                return $n;
            }
        }
        return null;
    };
    // 2. same band id, byte-equal
    $by_id = [];
    foreach ($s as $n => $c) {
        if ($c['id'] !== null) {
            $by_id[$c['id'] . "\0" . $c['component'] . "\0" . $c['bytes']]['list'][] = $n;
        }
    }
    foreach ($by_id as &$bucket) {
        $bucket['at'] = 0;
    }
    unset($bucket);
    foreach ($in as $key => $b) {
        if (isset($out[$key]) || $b['id'] === null) {
            continue;
        }
        $k = $b['id'] . "\0" . $b['component'] . "\0" . $b['bytes'];
        if (isset($by_id[$k]) && ($n = $take($by_id[$k])) !== null) {
            $claim($key, $n);
        }
    }
    // 3. any unused stored band, byte-equal
    $by_bytes = [];
    foreach ($s as $n => $c) {
        $by_bytes[$c['component'] . "\0" . $c['bytes']]['list'][] = $n;
    }
    foreach ($by_bytes as &$bucket) {
        $bucket['at'] = 0;
    }
    unset($bucket);
    foreach ($in as $key => $b) {
        if (isset($out[$key])) {
            continue;
        }
        $k = $b['component'] . "\0" . $b['bytes'];
        if (isset($by_bytes[$k]) && ($n = $take($by_bytes[$k])) !== null) {
            $claim($key, $n);
        }
    }
    // 4. any unused stored band, structurally equal (signatures computed only now). Bounded
    // by the M-8 caps so the comparison cannot cost more than judging would: a band holding
    // a value over the prop cap is never signed (it can only match byte for byte), and
    // EVERY byte signed, incoming or stored, is charged to ONE budget of the write's byte
    // cap. Once it is spent, signing stops: the remaining incoming bands count as changed,
    // so they are judged like any new content (and count toward the write's own caps).
    // Each stored band is signed at most once, in order, as incoming bands of its shape need
    // it, and filed under its signature. Never a long write, never an unjudged band.
    $budget = PP_CONTENT_WRITE_MAX_BYTES;
    $over_cap = static function (array $map): bool {
        foreach ($map as $v) {
            if (strlen($v) > PP_CONTENT_PROP_MAX_BYTES) {
                return true;
            }
        }
        return false;
    };
    $sign = static function (array &$band) use (&$budget): bool {
        if ($band['sig'] === null) {
            $budget -= array_sum(array_map('strlen', $band['map']));
            if ($budget < 0) {
                return false;
            }
            $band['sig'] = _pp_content_structural_key($band['map']);
        }
        return true;
    };
    $shape = static fn (array $band): string => $band['component'] . "\0" . implode("\0", array_keys($band['map']));
    $pending = [];   // shape => bucket of stored bands not yet signed, in order
    $signed = [];    // shape => signature => bucket of stored bands
    foreach ($s as $n => $c) {
        if (!isset($used[$n]) && !$over_cap($c['map'])) {
            $pending[$shape($c)]['list'][] = $n;
        }
    }
    foreach ($pending as &$bucket) {
        $bucket['at'] = 0;
    }
    unset($bucket);
    foreach ($in as $key => $b) {
        if (isset($out[$key]) || $over_cap($b['map'])) {
            continue;
        }
        $g = $shape($b);
        if (!isset($pending[$g]) && !isset($signed[$g])) {
            continue;
        }
        if (!$sign($in[$key])) {
            return $out;
        }
        $sig = $in[$key]['sig'];
        $n = isset($signed[$g][$sig]) ? $take($signed[$g][$sig]) : null;
        while ($n === null && isset($pending[$g]) && ($m = $take($pending[$g])) !== null) {
            if (!$sign($s[$m])) {
                return $out;
            }
            if ($s[$m]['sig'] === $sig) {
                $n = $m;
            } else {
                $signed[$g][$s[$m]['sig']]['list'][] = $m;
                $signed[$g][$s[$m]['sig']]['at'] ??= 0;
            }
        }
        if ($n !== null) {
            $claim($key, $n);
        }
    }
    return $out;
}

/**
 * The parsed structure §2.2 compares: element names, the self-closing flag, attribute
 * names, decoded attribute values (a style attribute as its declaration list), decoded
 * text, and the text of raw-text and RCDATA elements (title, textarea, style, script, …),
 * which this tokenizer reports on the tag token itself. Entity spelling, quote style,
 * attribute order and whitespace inside tags normalise away.
 */
function pp_content_structural_signature(string $bytes): string {
    $p = new WP_HTML_Tag_Processor($bytes);
    $parts = [];
    while ($p->next_token()) {
        $type = $p->get_token_type();
        if ($type === '#tag') {
            $attrs = [];
            foreach (($p->get_attribute_names_with_prefix('') ?? []) as $a) {
                $v = $p->get_attribute($a);
                if ($a === 'style' && is_string($v)) {
                    $v = implode(';', array_map(static fn ($d) => preg_replace('/\s*:\s*/', ':', $d, 1), _pp_content_split_declarations($v)));
                }
                $attrs[strtolower((string) $a)] = $v;
            }
            ksort($attrs);
            $text = $p->get_modifiable_text();
            if ($text !== '') {
                // A raw-text or RCDATA body is reported DECODED, and an SVG <title> reads
                // `&lt;img&gt;` and `<img>` differently, so such a prop matches only byte for
                // byte.
                return md5('raw:' . $bytes);
            }
            $parts[] = [($p->is_tag_closer() ? '/' : '') . strtolower((string) $p->get_tag()),
                $p->has_self_closing_flag(), $attrs];
        } else {
            // Only an ordinary HTML comment compares by text. A CDATA section, a bogus or
            // funky comment, a processing instruction or a presumptuous tag can build a
            // different tree in foreign content from the same text, so such a prop
            // matches only byte for byte.
            if ($type !== '#text' && !($type === '#comment'
                && $p->get_comment_type() === WP_HTML_Tag_Processor::COMMENT_AS_HTML_COMMENT)) {
                return md5('raw:' . $bytes);
            }
            $parts[] = [$type, $p->get_modifiable_text()];
        }
    }
    // A tokenizer stopped on an unfinished token has not read the rest, and the page's next
    // `>` completes it: the prop matches only byte for byte.
    if ($p->paused_at_incomplete_token()) {
        return md5('raw:' . $bytes);
    }
    return md5(serialize($parts));
}

/**
 * The plugin-boundary and page-global disclosures (P-23 / P-9; E11 as ruled 2026-10-05):
 * custom elements and customized built-ins, and ids or names that pre-empt a global a page
 * script defines or reads, as info findings. ONE lexical pass per prop, and only over props
 * that can hold one: this runs over every band on every accepted write. The predicate
 * records the custom-element facts as `notes` for the render side too.
 *
 * @return list<array{type:string, message:string, index:int|string}>
 */
function pp_content_composition_disclosures(array $items): array {
    $out = [];
    $globals_table = pp_content_clobber_table()['globals'];
    $named = pp_content_named_access_elements();
    foreach ($items as $key => $item) {
        if (!is_array($item)) {
            continue;
        }
        $names = [];
        $globals = [];
        foreach (pp_content_band_values($item) as [, , , $value]) {
            if (strlen($value) > PP_CONTENT_PROP_MAX_BYTES
                || !preg_match('/<[A-Za-z][^\s>\/]*-|(?:is|id|name)\s*=/i', $value)) {
                continue;
            }
            $p = new WP_HTML_Tag_Processor($value);
            while ($p->next_tag()) {
                $tag = strtolower((string) $p->get_tag());
                if (pp_content_is_valid_custom_element_name($tag)) {
                    $names[] = '<' . $tag . '>';
                }
                $is = $p->get_attribute('is');
                if (is_string($is) && $is !== '') {
                    $names[] = '<' . $tag . ' is="' . _pp_content_reflect($is, 64) . '">';
                }
                $id = $p->get_attribute('id');
                if (is_string($id) && isset($globals_table[$id])) {
                    $globals[] = $id;
                }
                // Window named access also exposes these elements' names.
                $name = $p->get_attribute('name');
                if (isset($named[$tag]) && is_string($name) && isset($globals_table[$name])) {
                    $globals[] = $name;
                }
            }
        }
        $band = is_int($key) ? (string) $key : '"' . _pp_content_reflect((string) $key, 64) . '"';
        if ($globals !== []) {
            $out[] = [
                'type'    => 'content_global_shadow',
                'message' => sprintf(
                    'Component %s content uses the id or name %s, the name of a global that WordPress page scripts define or read. '
                    . 'Such an element becomes a same-named global until a script defines its own, so a script that checks for it '
                    . 'first may find the element (E11, disclosed rather than refused).',
                    $band,
                    '"' . implode('", "', array_map(static fn ($g) => _pp_content_reflect($g, 64), array_values(array_unique($globals)))) . '"'
                ),
                'index'   => $key,
            ];
        }
        if ($names !== []) {
            $names = array_values(array_unique($names));
            $out[] = [
                'type'    => 'content_plugin_output',
                'message' => sprintf(
                    'Component %s content uses %s. A custom element paints through its class, style and the band\'s styling; '
                    . 'it gains behaviour only if an installed plugin\'s script defines it, which the theme does not review '
                    . '(the plugin boundary, LAYER-3-CONTRACT.md P-9/P-23).',
                    $band,
                    implode(', ', array_slice($names, 0, 8)) . (count($names) > 8 ? sprintf(' and %d more', count($names) - 8) : '')
                ),
                'index'   => $key,
            ];
        }
    }
    return $out;
}

/**
 * E6's props-side rule: a write that ADDS a band anchor equal to an id already inside
 * band content (another band's, or its own unchanged content, which the content gate
 * would not re-check) is the write refused, on the band whose anchor is new (the #1007
 * rule: the refusal lands on the band being changed). An anchor is "added" when no stored
 * band carried it.
 *
 * @param  array      $items     The composition being written.
 * @param  array|null $baseline  The stored composition (null: every anchor is new).
 * @param  int|null   $only      Judge only this band's anchor (band-scoped writes).
 * @param  array|null $index     pp_content_composition_index($items), when the caller has it.
 * @return list<array{index:int|string, message:string}>
 */
function pp_content_anchor_collisions(array $items, ?array $baseline, $only = null, ?array $index = null): array {
    $stored_anchors = [];
    foreach ((array) $baseline as $s) {
        if (is_array($s) && isset($s['props']['id']) && is_string($s['props']['id'])) {
            $stored_anchors[$s['props']['id']] = true;
        }
    }
    $out = [];
    $content_ids = null;
    foreach ($items as $key => $item) {
        if ($only !== null && $key !== $only) {
            continue;
        }
        $anchor = (is_array($item) && isset($item['props']['id']) && is_string($item['props']['id'])) ? $item['props']['id'] : '';
        if ($anchor === '' || isset($stored_anchors[$anchor])) {
            continue;
        }
        if ($content_ids === null) {
            // The caller's index when it has one; otherwise one built under the write's
            // budget (this runs on writes that judge no band at all).
            $index ??= pp_content_composition_index($items, []);
            $content_ids = [];
            $incomplete = false;
            foreach ($index as $k => $facts) {
                $incomplete = $incomplete || !empty($facts['incomplete']);
                foreach ($facts['ids'] ?? [] as $id) {
                    $content_ids[$id][] = $k;
                }
            }
        }
        if ($incomplete && !isset($content_ids[$anchor])) {
            // Uncertainty is not admission: the content this anchor could collide with was
            // not all read within the write's budget.
            $out[] = [
                'index'   => $key,
                'message' => sprintf(
                    'Component "%s" prop "id" "%s" is a new band anchor, and this page\'s other bands hold more content than one write checks for ids (%d bytes), so it cannot be verified unique (LAYER-3-CONTRACT.md E6). Keep an existing anchor, or make the page smaller.',
                    is_scalar($item['component'] ?? null) ? (string) $item['component'] : '?',
                    _pp_content_reflect($anchor, 64),
                    PP_CONTENT_WRITE_MAX_BYTES
                ),
            ];
            continue;
        }
        foreach ($content_ids[$anchor] ?? [] as $holder) {
            $out[] = [
                'index'   => $key,
                'message' => sprintf(
                    'Component "%s" prop "id" "%s" equals an id inside the content of item %s, and a band anchor must stay unique on the page (LAYER-3-CONTRACT.md E6). Choose another anchor, or change that id first.',
                    is_scalar($item['component'] ?? null) ? (string) $item['component'] : '?',
                    _pp_content_reflect($anchor, 64),
                    is_int($holder) ? (string) $holder : '"' . _pp_content_reflect((string) $holder, 64) . '"'
                ),
            ];
            break;
        }
    }
    return $out;
}
