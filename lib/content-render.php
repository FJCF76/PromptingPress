<?php
/**
 * lib/content-render.php — Layer 3A, the RENDER side (LAYER-3-CONTRACT.md §2.3, #1242 T3b).
 *
 * This file sanitizes nothing. Every stored content prop is rendered from the ONE
 * predicate's own render view (`pp_content_sanitize()`'s `html`, lib/content.php), run over
 * the stored bytes with the composition's cross-band context, exactly as the write gate and
 * the findings run it. What lives here is the plumbing that gives a template that view:
 *
 *   render loop ──begin($items, $post_id)──► one pp_content_composition_index() walk
 *        │                                     (every content value walked ONCE, states kept)
 *        ├─ band($key) ─► template ─► pp_content_prop_html('section', 'body', $value)
 *        │                                │
 *        │                                ├─ sink from pp_content_prop_contracts() (one table)
 *        │                                ├─ the band's finished state for those bytes
 *        │                                │    rich / rich_cell / inline: the predicate's html
 *        │                                │      (a construct stripped; a whole-prop clause,
 *        │                                │       E10 / P-16 / M-8: empty)
 *        │                                │    heading: markup ONLY when it passes AND the
 *        │                                │      bytes were admitted by a 3A-gated write
 *        │                                │      (the stored-intent rule); else esc_html()
 *        │                                └─ no active context ─► the legacy path (below)
 *        └─ end()
 *
 * THE LEGACY PATH. A template also renders props that are not stored composition bytes:
 * page.php / single.php hand `the_content` to a section body, home / archive / search / 404
 * pass literal or WordPress-generated titles, and the posts listing fills grid cards from
 * the query. §2.3 governs stored bytes, so outside a composition render context those
 * props render exactly as before (wp_kses_post / pp_kses_inline / esc_html). Every loop that
 * renders STORED bands begins a context (pinned by a source scan).
 *
 * THE STORED-INTENT RULE (#1242 routed item 17, mechanism chosen in T3b's plan review). A
 * title or heading renders as markup only when its exact bytes passed the 3A write gate on
 * this page: the gate notes each passing heading value of a band it judges, and the one
 * `_pp_composition` writer records those hashes beside the composition under its lock
 * (`_pp_content_verified`). A heading stored before the gate (`The <span> element`, written
 * as text) therefore keeps rendering as the text it was, even though it would pass the
 * predicate today; a heading that FAILS the predicate renders fully escaped too, and is
 * listed by the census. Restores (#233) and raw meta writes never add a hash.
 *
 * NO try/catch here (§2.4). The legacy path (_pp_content_legacy_html()) does enter kses, so
 * every catch that can enclose a component render re-hooks `pre_kses` in its `finally`
 * (pp_content_rehook_pre_kses()).
 */

/**
 * Post meta holding what a page's 3A-gated writes vouched for (pp_content_vouched()).
 *
 * THE VOUCH LIFECYCLE, the design contract of the stored-intent marker (#1242 T3b; orchestrator
 * rulings of 2026-10-05). A vouch is security machinery: it decides whether stored bytes render
 * as markup and with the widened set. Its notes therefore live exactly one action:
 *
 *   1. RESET at validation start: every gated validation (pp_validate_composition_errors(),
 *      the content gate on) empties the request's notes before judging.
 *   2. STAMPED to composition AND post: notes are added only when the whole validation
 *      passed, stamped with the content of the composition judged
 *      (pp_content_composition_scope()); the action binds them to its target page
 *      (pp_content_bind_vouch_target(): the action's post_id, or the page create_page makes).
 *   3. SPENT on every exit: the writer consumes them whether it commits or refuses
 *      (pp_content_reset_vouches()); the action spends them on a failed validation and in a
 *      `finally` after its execute (pp_content_end_vouch_action()); a batch rollback spends
 *      them before it restores.
 *
 * pp_content_record_verified() records only notes whose stamp matches the committed
 * composition AND whose target is the committed page. So no note can survive into a write it
 * was not judged for: a restore, a rollback, a raw meta write, another page, a later step.
 */
const PP_CONTENT_VERIFIED_META = '_pp_content_verified';

/**
 * How many vouched entries a page keeps (oldest not in the composition drop first): two per
 * value of one write's value cap, so a page's own content never pushes itself out.
 */
const PP_CONTENT_VERIFIED_MAX = 2 * PP_CONTENT_WRITE_MAX_VALUES;

/** The render-context stack (a posts page renders its own bands inside a page render). */
function &_pp_content_render_stack(): array {
    static $stack = [];
    return $stack;
}

/**
 * Opens a render context over a stored composition. One predicate walk per content value;
 * a band's finished results are built on its first lookup.
 *
 * @param array $items    The composition as stored.
 * @param int   $post_id  The page it belongs to (its verified-heading set); 0 = none.
 */
function pp_content_render_begin(array $items, int $post_id = 0): void {
    $stack = &_pp_content_render_stack();
    $stack[] = [
        'items'   => $items,
        'post_id' => $post_id,
        'index'   => null,
        'counts'  => null,
        'bands'   => [],
        'band'    => null,
        'misses'  => [],   // values a template read that the stored band does not hold
        'miss_bytes' => 0, // what those cost against the render budget
        'miss_values' => 0,
        'budget_left' => [0, 0], // [bytes, values] the index walk left (set with the index)
    ];
}

/** Selects the band the next template call renders. */
function pp_content_render_band($key): void {
    $stack = &_pp_content_render_stack();
    if ($stack !== []) {
        $stack[count($stack) - 1]['band'] = $key;
    }
}

/** Closes the innermost render context. */
function pp_content_render_end(): void {
    $stack = &_pp_content_render_stack();
    array_pop($stack);
}

/** Is a stored band being rendered right now? */
function pp_content_render_active(): bool {
    $stack = &_pp_content_render_stack();
    return $stack !== [] && end($stack)['band'] !== null;
}

/**
 * The finished predicate results of one band of the active context, keyed by
 * "<contract path>\0<bytes>", each with its message label. A value the render's budget did
 * not reach carries `unchecked` and no result (it renders through the legacy path).
 *
 * @return array<string, array{label:string, sink:string, result:?array, unchecked:bool}>
 */
function _pp_content_render_band_results(array &$frame, $key): array {
    if (isset($frame['bands'][$key])) {
        return $frame['bands'][$key];
    }
    $items = $frame['items'];
    if (!is_array($items[$key] ?? null)) {
        return $frame['bands'][$key] = [];
    }
    // Every band's facts are read once per render context: the cross-band rules (E6, E12)
    // need the whole page, and a band's own walk is finished, never repeated.
    if ($frame['index'] === null) {
        [$frame['index'], $frame['counts'], $frame['budget_left']] = _pp_content_render_index($items, (int) $frame['post_id']);
    }
    $out = [];
    foreach (_pp_content_finish_band($items, $key, $frame['index'], $frame['counts']) as $v) {
        $out[$v['path'] . "\0" . $v['value']] = $v;
    }
    return $frame['bands'][$key] = $out;
}

/**
 * One band's values, each finished as the render finishes it (shared by the render and the
 * report). A value without a walk state is one the render budget did not reach.
 *
 * @return list<array{label:string, path:string, sink:string, value:string, result:?array, unchecked:bool}>
 */
function _pp_content_finish_band(array $items, $key, array $index, array $counts): array {
    $facts = $index[$key] ?? null;
    if (!is_array($facts) || $facts['values'] === []) {
        return [];
    }
    $ctx = pp_content_band_context($items, $key, $index, $counts);
    $out = [];
    foreach ($facts['values'] as $n => [$label, $path, $sink, $value]) {
        $walked = isset($facts['states'][$n]);
        $out[] = ['label' => $label, 'path' => $path, 'sink' => $sink, 'value' => $value,
            'result' => $walked ? _pp_content_finish($facts['states'][$n], $ctx) : null, 'unchecked' => !$walked];
    }
    return $out;
}

/**
 * The composition's render index and counts (pp_content_render_composition_index(): the
 * per-value tier and the render budget), memoized for the LAST composition and vouched set
 * seen: the presence probe opens one context per band of the same composition, which would
 * otherwise walk the whole page once per band.
 *
 * @return array{0:array, 1:array, 2:array{0:int,1:int}}  index, counts, [bytes, values] left
 */
function _pp_content_render_index(array $items, int $post_id): array {
    static $memo = null;
    $vouched = pp_content_vouched($post_id)['f'] + (_pp_content_verified_registry()['f'] ?? []);
    ksort($vouched);
    $key = hash('sha256', serialize($items) . "\0" . implode(',', array_keys($vouched)));
    if ($memo === null || $memo[0] !== $key) {
        $left = [0, 0];
        $index = pp_content_render_composition_index($items, $vouched, $left);
        $memo = [$key, $index, pp_content_index_counts($index), $left];
    }
    return [$memo[1], $memo[2], $memo[3]];
}

/** The content sink of a component's prop path ('plain' when the contract names none). */
function pp_content_prop_sink(string $component, string $path): string {
    return pp_content_prop_contracts()[$component][$path] ?? 'plain';
}

/**
 * The HTML a template echoes for one content prop.
 *
 * @param string $component  The template's component name.
 * @param string $path       The prop's contract path (`body`, `items[].text`, `rows[][]`, ...).
 * @param mixed  $value      The prop value as the template read it.
 * @return string  Safe to echo.
 */
function pp_content_prop_html(string $component, string $path, $value): string {
    $sink = pp_content_prop_sink($component, $path);
    // A stored number renders as its digits, as the templates' (string) casts always made
    // it; a non-scalar has no text (the #730 guard: never handed to a typed call).
    if (is_scalar($value) && !is_string($value)) {
        $value = (string) $value;
    }
    if (!is_string($value) || $value === '') {
        return '';
    }
    $entry = _pp_content_render_entry($path, $sink, $value);
    if ($entry === null || $entry['result'] === null) {
        // Outside a composition, or past the render budget (`content_not_checked`).
        return _pp_content_legacy_html($sink, $value);
    }
    if ($sink === 'heading') {
        return pp_content_heading_is_markup($value, $entry['result'], $entry['post_id'])
            ? $entry['result']['html']
            : esc_html($value);
    }
    return $entry['result']['html'];
}

/**
 * The active band's predicate result for one value, or null outside a render context. The
 * result is null for a value the render budget did not reach.
 *
 * @return array{result:?array, post_id:int}|null
 */
function _pp_content_render_entry(string $path, string $sink, string $value): ?array {
    if (!pp_content_render_active()) {
        return null;
    }
    $stack = &_pp_content_render_stack();
    $frame = &$stack[count($stack) - 1];
    $results = _pp_content_render_band_results($frame, $frame['band']);
    $entry = $results[$path . "\0" . $value] ?? null;
    if ($entry === null) {
        // The template read a value the stored band does not hold byte for byte (a default,
        // a trimmed copy): it is still judged, with this band's context and at core parity
        // (nothing vouched for these bytes), never passed raw. It is charged to the same
        // render budget (ruling Q2) and judged once per context: past the budget, or in a
        // band the budget did not finish, it takes the legacy path like any unchecked value.
        $miss_key = $sink . "\0" . $value;
        if (!array_key_exists($miss_key, $frame['misses'])) {
            $band_facts = is_array($frame['index'] ?? null) ? ($frame['index'][$frame['band']] ?? null) : null;
            // Charged against what the index walk LEFT of the budget (one bound per context).
            $frame['miss_bytes'] += strlen($value);
            $frame['miss_values']++;
            if (!is_array($band_facts) || !empty($band_facts['incomplete'])
                || $frame['miss_bytes'] > $frame['budget_left'][0] || $frame['miss_values'] > $frame['budget_left'][1]) {
                $frame['misses'][$miss_key] = null;
            } else {
                $ctx = pp_content_band_context($frame['items'], $frame['band'], $frame['index'], $frame['counts']);
                $ctx['tier'] = 'core';
                $frame['misses'][$miss_key] = pp_content_sanitize($value, $sink, $ctx);
            }
        }
        return ['result' => $frame['misses'][$miss_key], 'post_id' => (int) $frame['post_id']];
    }
    return ['result' => $entry['result'], 'post_id' => (int) $frame['post_id']];
}

/** Today's render of a prop that is not a stored composition value (see the file header). */
function _pp_content_legacy_html(string $sink, string $value): string {
    switch ($sink) {
        case 'rich':
        case 'rich_cell':
            return wp_kses_post($value);
        case 'inline':
            return pp_kses_inline($value);
        default:
            return esc_html($value);
    }
}

/**
 * The stored-intent rule: does this heading render as markup? Only when it holds markup at
 * all, the predicate admits it whole, and its bytes were admitted by a 3A-gated write on
 * this page (or are being judged by one in this request: the editor preview).
 */
function pp_content_heading_is_markup(string $value, array $result, int $post_id): bool {
    if (strpos($value, '<') === false || $result['losses'] !== []) {
        return false;
    }
    $hash = pp_content_value_hash($value);
    return isset(pp_content_vouched($post_id)['h'][$hash]) || isset(_pp_content_verified_registry()['h'][$hash]);
}

/** One content value's vouching hash. */
function pp_content_value_hash(string $value): string {
    return substr(hash('sha256', $value), 0, 32);
}

/**
 * What a page's gated writes vouched for (`_pp_content_verified`), as two sets of hashes:
 *   h  a title or heading whose markup a gated write admitted (it renders as markup);
 *   f  a content value a FULL-tier gated write admitted (it renders with the widened set;
 *      every other stored value renders at core `post` parity, ruling Q1).
 * Stored as one JSON list of 33-character entries (kind + hash). Decoded once per stored
 * value: the memo is keyed by the meta row's exact bytes.
 *
 * @return array{h: array<string,true>, f: array<string,true>}
 */
function pp_content_vouched(int $post_id): array {
    $none = ['h' => [], 'f' => []];
    if ($post_id < 1) {
        return $none;
    }
    static $memo = [];
    $raw = get_post_meta($post_id, PP_CONTENT_VERIFIED_META, true);
    if (!is_string($raw) || $raw === '') {
        return $none;
    }
    if (isset($memo[$post_id]) && $memo[$post_id][0] === $raw) {
        return $memo[$post_id][1];
    }
    $list = json_decode($raw, true);
    $out = $none;
    foreach (is_array($list) ? $list : [] as $entry) {
        if (is_string($entry) && preg_match('/^([hf])([0-9a-f]{32})\z/', $entry, $m)) {
            $out[$m[1]][$m[2]] = true;
        }
    }
    $memo[$post_id] = [$raw, $out];
    return $out;
}

/**
 * What the 3A gate vouched for in the write being validated in THIS request (`h` and `f` sets
 * of hashes, as pp_content_vouched()), noted only when the whole validation passed (a refused
 * write vouches for nothing) and CONSUMED by the next commit (pp_content_record_verified()),
 * which records only what the committed composition holds. Not keyed by page: the shared
 * validator is called without one; the commit that follows the validation is the scope.
 */
function &_pp_content_verified_registry(): array {
    static $registry = ['h' => [], 'f' => [], 'scope' => null];
    return $registry;
}

/** The page the running action may record vouches for (null: none; see the lifecycle above). */
function &_pp_content_vouch_target(): ?int {
    static $target = null;
    return $target;
}

/** Binds the running action's vouch notes to the page it writes (the lifecycle, step 2). */
function pp_content_bind_vouch_target(?int $post_id): void {
    $target = &_pp_content_vouch_target();
    $target = ($post_id !== null && $post_id > 0) ? $post_id : null;
}

/**
 * Ends an action's vouch lifecycle (step 3): its notes are spent and its target unbound,
 * whether it wrote or not. Called by pp_execute_action() on a failed validation and in a
 * `finally` after execute, and by the batch rollback before it restores.
 */
function pp_content_end_vouch_action(): void {
    pp_content_reset_vouches();
    pp_content_bind_vouch_target(null);
}

/**
 * The content identity of a composition: every content value it holds, as a sorted list,
 * hashed. A gated validation stamps its notes with the composition it judged, and the commit
 * records them only for that composition: notes from a validation whose action then refused
 * never vouch for a different write in the same request (a batch step, a rollback's restore).
 */
function pp_content_composition_scope(array $items): string {
    $values = [];
    foreach ($items as $item) {
        if (is_array($item)) {
            foreach (pp_content_band_values($item) as [, $path, , $value]) {
                $values[] = $path . "\0" . $value;
            }
        }
    }
    sort($values);
    return hash('sha256', implode("\0\0", $values));
}

/** The heading values of a band that hold markup (only those need a verification). */
function pp_content_band_markup_headings(array $item): array {
    $out = [];
    foreach (pp_content_band_values($item) as [, $path, $sink, $value]) {
        if ($sink === 'heading' && $path !== 'title_accent' && strpos($value, '<') !== false) {
            $out[] = $value;
        }
    }
    return $out;
}

/** Every content value a composition holds that carries markup, as a set keyed by the value. */
function pp_content_stored_value_set(array $stored): array {
    $set = [];
    foreach ($stored as $item) {
        if (is_array($item)) {
            foreach (pp_content_band_values($item) as [, , , $value]) {
                $set[$value] = true;
            }
        }
    }
    return $set;
}

/**
 * What a judged and admitted band vouches for: the values it SENDS AS NEW BYTES (bytes no
 * stored content value on the page holds). Re-sending stored content unchanged (the editor
 * and the AI re-send whole bands) never vouches for it, so a legacy value keeps rendering as
 * it did when another prop of its band is edited; to change how it renders, an author edits
 * it. Markup headings are vouched as markup (`h`); any new value with markup written at the
 * full tier is vouched for the widened set (`f`).
 *
 * @return array{h: list<string>, f: list<string>}
 */
function pp_content_band_vouches(array $item, array $stored_set, string $tier): array {
    $out = ['h' => [], 'f' => []];
    foreach (pp_content_band_values($item) as [, $path, $sink, $value]) {
        if (strpos($value, '<') === false || isset($stored_set[$value])) {
            continue;
        }
        if ($sink === 'heading' && $path !== 'title_accent') {
            $out['h'][] = $value;
        }
        if ($tier === 'full') {
            $out['f'][] = $value;
        }
    }
    return $out;
}

/**
 * Empties this request's registry and returns what it held. The writer calls it on EVERY exit
 * (a commit consumes it; a failed commit discards it), so notes from one validation never
 * reach a later write in the same request (a batch rollback's restore, a CLI loop).
 *
 * @return array{h: array<string,true>, f: array<string,true>}
 */
function pp_content_reset_vouches(): array {
    $registry = &_pp_content_verified_registry();
    $noted = $registry + ['h' => [], 'f' => [], 'scope' => null];
    $registry = ['h' => [], 'f' => [], 'scope' => null];
    return $noted;
}

/**
 * Adds what a band vouches for to this request's registry (the gate on an accepted
 * validation; the preview), stamped with the composition it was judged in (null: no stamp,
 * the preview, which never commits).
 */
function pp_content_note_vouches(array $vouches, ?string $scope = null): void {
    $registry = &_pp_content_verified_registry();
    if ($scope !== null) {
        $registry['scope'] = $scope;
    }
    foreach (['h', 'f'] as $kind) {
        foreach ($vouches[$kind] ?? [] as $value) {
            $registry[$kind][pp_content_value_hash((string) $value)] = true;
        }
    }
}

/**
 * The commit half: called by pp_update_composition() under its lock, after it stores the
 * composition. Records what the gate vouched for in this write that the stored composition
 * holds, and consumes the registry. Keeps the earlier entries, newest first, bounded (entries
 * the composition no longer holds are the first to go).
 */
function pp_content_record_verified(int $post_id, array $composition): void {
    $noted = pp_content_reset_vouches();
    // Only the write the notes were judged for may record them: the same composition, on the
    // page the action targets (the lifecycle, step 2).
    if (($noted['h'] === [] && $noted['f'] === []) || $noted['scope'] === null
        || _pp_content_vouch_target() !== $post_id
        || !hash_equals($noted['scope'], pp_content_composition_scope($composition))) {
        return;
    }
    $present = [];
    foreach (array_keys(pp_content_stored_value_set($composition)) as $value) {
        $present[pp_content_value_hash((string) $value)] = true;
    }
    $new = [];
    foreach (['h', 'f'] as $kind) {
        foreach (array_keys(array_intersect_key($noted[$kind], $present)) as $hash) {
            $new[] = $kind . $hash;
        }
    }
    if ($new === []) {
        return;
    }
    // Read under the writer's lock past the request's meta cache: a list cached earlier in
    // this request may predate another writer's commit, and merging onto it would drop that
    // writer's entries.
    if (function_exists('wp_cache_delete')) {
        wp_cache_delete($post_id, 'post_meta');
    }
    $old = [];
    foreach (pp_content_vouched($post_id) as $kind => $set) {
        foreach (array_keys($set) as $hash) {
            $old[] = $kind . $hash;
        }
    }
    $list = array_values(array_unique(array_merge($new, $old)));
    if (count($list) > PP_CONTENT_VERIFIED_MAX) {
        $keep = array_values(array_filter($list, static fn ($e) => isset($present[substr($e, 1)])));
        $rest = array_values(array_filter($list, static fn ($e) => !isset($present[substr($e, 1)])));
        $list = array_slice(array_merge($keep, $rest), 0, PP_CONTENT_VERIFIED_MAX);
    }
    update_post_meta($post_id, PP_CONTENT_VERIFIED_META, wp_json_encode($list));
}

/**
 * Renders a title with its optional accent from inside the active render context
 * (pp_render_heading_with_accent() delegates here). Returns null when the title renders as
 * text, so the caller keeps its plain-text accent path byte for byte.
 *
 * On the markup path the accent is matched against the TEXT of the parsed title: the
 * predicate's html is its own canonical serialization, in which every `<` opens a tag and
 * every text run is entity-encoded text. Each run is decoded, the first run holding the
 * accent's text has that part wrapped in the accent span, and the pieces are re-encoded.
 * Tags and attributes are never searched or split (#1242 routed item 13).
 */
function pp_content_title_markup_html(string $title, string $accent, string $accent_class): ?string {
    if (strpos($title, '<') === false) {
        return null;
    }
    $entry = _pp_content_render_entry('title', 'heading', $title);
    // Null result: a title the render budget did not reach takes the plain-text path.
    if ($entry === null || $entry['result'] === null
        || !pp_content_heading_is_markup($title, $entry['result'], $entry['post_id'])) {
        return null;
    }
    $html = $entry['result']['html'];
    $needle = pp_content_text_of($accent);
    if ($needle === '') {
        return $html;
    }
    $parts = preg_split('/(<[^>]*>)/', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
    $done = false;
    foreach ($parts as $i => $part) {
        if ($done || $part === '' || $part[0] === '<') {
            continue;
        }
        $text = html_entity_decode($part, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $pos = strpos($text, $needle);
        if ($pos === false) {
            continue;
        }
        $enc = static fn (string $t): string => htmlspecialchars($t, ENT_NOQUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
        $parts[$i] = $enc(substr($text, 0, $pos))
            . '<span class="' . esc_attr($accent_class) . '">' . $enc($needle) . '</span>'
            . $enc(substr($text, $pos + strlen($needle)));
        $done = true;
    }
    return implode('', $parts);
}

/** The text an accent stands for: its decoded text, markup contributing only its text. */
function pp_content_text_of(string $value): string {
    if (strpos($value, '<') === false) {
        return html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    $p = new WP_HTML_Tag_Processor($value);
    $text = '';
    while ($p->next_token()) {
        if ($p->get_token_type() === '#text') {
            $text .= $p->get_modifiable_text();
        }
    }
    return $text;
}

/**
 * The editor preview's half of the stored-intent rule: the bands a save of this composition
 * would judge (§2.6's one-to-one matching against the stored page) and admit vouch, in this
 * request, for what they send as new bytes, exactly as the write gate would. Nothing is
 * recorded (no write happens); the preview only renders as the page will once saved. Under
 * the write path's own M-8 caps: a preview whose changed content a write would refuse as too
 * large vouches for nothing (and the render budget bounds the rest).
 *
 * @param array $items     The composition the editor sent.
 * @param array $baseline  The stored composition (pp_content_stored_baseline()).
 */
function pp_content_note_judged_bands(array $items, array $baseline): void {
    $unchanged = pp_content_unchanged_keys($items, $baseline);
    $stored = pp_content_stored_value_set($baseline);
    $tier = pp_content_write_tier();
    $judged = [];
    $budget = ['bytes' => 0, 'values' => 0];
    foreach ($items as $key => $item) {
        if (!is_array($item) || isset($unchanged[$key]) || !is_string($item['component'] ?? null)) {
            continue;
        }
        $v = pp_content_band_vouches($item, $stored, $tier);
        if ($v['h'] === [] && $v['f'] === []) {
            continue;
        }
        if (pp_content_band_size_errors($item, $item['component'], $budget) !== []) {
            return;
        }
        $judged[] = $key;
    }
    if ($judged === []) {
        return;
    }
    $index = pp_content_composition_index($items, $judged, $tier);
    $counts = pp_content_index_counts($index);
    foreach ($judged as $key) {
        if (pp_content_band_losses($items, $key, $index, $counts) === []) {
            pp_content_note_vouches(pp_content_band_vouches($items[$key], $stored, $tier));
        }
    }
}

/**
 * §2.4 (#730 inherited): re-adds core's block-attribute `pre_kses` filter. Core's
 * wp_pre_kses_block_attributes() unhooks itself while it runs and re-hooks after; a throw
 * caught in between would leave every later wp_kses() call in the request without it.
 * Every catch that can enclose a component render calls this in its `finally` (add_filter
 * is idempotent for the same callback and priority, so an un-thrown render changes nothing).
 */
function pp_content_rehook_pre_kses(): void {
    if (function_exists('wp_pre_kses_block_attributes')) {
        add_filter('pre_kses', 'wp_pre_kses_block_attributes', 10, 3);
    }
}
