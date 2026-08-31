<?php
/**
 * lib/wp.php — PromptingPress WP Abstraction Layer
 *
 * THE ONLY file that calls WordPress functions directly.
 * Templates and components call ONLY these pp_* wrappers.
 * This is the stable contract — AI edits templates freely using these functions.
 */

/**
 * Returns the site name.
 */
function pp_site_title(): string {
    return get_bloginfo('name');
}

/**
 * Returns the site tagline/description.
 */
function pp_site_description(): string {
    return get_bloginfo('description');
}

/**
 * Returns an absolute URL, optionally with a path appended.
 */
function pp_site_url(string $path = ''): string {
    return home_url($path);
}

/**
 * Returns the current page/post title.
 */
function pp_page_title(): string {
    return get_the_title();
}

/**
 * Returns the current page/post content with standard WP filters applied.
 */
function pp_page_content(): string {
    return apply_filters('the_content', get_the_content());
}

/**
 * Returns an ACF field value, or null if ACF is not installed.
 *
 * @param string          $name  ACF field name.
 * @param int|string|null $id    Optional post/option ID.
 */
function pp_field(string $name, $id = null) {
    if (function_exists('get_field')) {
        return get_field($name, $id);
    }
    return null;
}

/**
 * Renders a registered WP nav menu by theme location.
 * Outputs nothing when the location has no assigned menu (fallback_cb false).
 *
 * @param string $location  Theme location slug (e.g. 'primary', 'footer').
 */
function pp_nav_menu(string $location): void {
    wp_nav_menu([
        'theme_location' => $location,
        'container'      => false,
        'fallback_cb'    => false,
    ]);
}

/**
 * Returns a WP_Query object for the given args.
 *
 * @param array $args  WP_Query args.
 * @return \WP_Query
 */
function pp_posts(array $args = []): \WP_Query {
    return new \WP_Query($args);
}

/**
 * Iterates a WP_Query using a callback and resets post data after.
 *
 * @param \WP_Query $query
 * @param callable  $cb    Called once per post with the global post set.
 */
function pp_the_loop(\WP_Query $query, callable $cb): void {
    try {
        while ($query->have_posts()) {
            $query->the_post();
            $cb();
        }
    } finally {
        wp_reset_postdata();
    }
}

/**
 * Returns the main WP_Query for the current request (#126).
 *
 * WordPress already builds and filters this query correctly for the route —
 * category/tag/date/author archives, the posts index (is_home) — before any
 * template loads. Prefer this over pp_posts() with a fresh WP_Query when
 * rendering "the listing for this route": a fresh query can only approximate
 * the archive context (and easily gets it wrong, e.g. showing every post on
 * a category archive), where the main query already has it exactly right.
 *
 * @return \WP_Query
 */
function pp_main_query(): \WP_Query {
    global $wp_query;
    return $wp_query;
}

/**
 * Returns pagination markup for the current main query (#126), or '' when
 * there's only one page. Wraps paginate_links() — the only place in the
 * theme this is called, keeping the "only lib/wp.php touches WP" invariant.
 *
 * @return string  A <nav> element with page links, or ''.
 */
function pp_pagination(): string {
    global $wp_query;
    $total = (int) ($wp_query->max_num_pages ?? 1);
    if ($total <= 1) {
        return '';
    }

    $links = paginate_links([
        'total'     => $total,
        'current'   => max(1, (int) get_query_var('paged')),
        'prev_text' => '← Previous',
        'next_text' => 'Next →',
        'type'      => 'array',
    ]);

    if (empty($links)) {
        return '';
    }

    return '<nav class="pp-pagination" aria-label="Posts pagination"><ul class="pp-pagination__list"><li>'
        . implode('</li><li>', $links)
        . '</li></ul></nav>';
}

/**
 * Returns the raw search query string for the current search request (issue 138).
 *
 * @return string
 */
function pp_search_query(): string {
    return get_search_query();
}

/**
 * Returns the total number of matched posts for the current main query
 * (issue 138) — `found_posts` reflects the full result count across all
 * pages, unlike `pp_main_query()->posts`, which only holds the current page.
 *
 * @return int
 */
function pp_result_count(): int {
    global $wp_query;
    return (int) ($wp_query->found_posts ?? 0);
}

/**
 * Returns true when the current page is the configured front page.
 */
function pp_is_front_page(): bool {
    return is_front_page();
}

/**
 * Returns space-separated body classes for the current page.
 */
function pp_body_classes(): string {
    return implode(' ', get_body_class());
}

/**
 * Returns a trimmed excerpt for the current post.
 *
 * @param int $length  Word count (default 55).
 */
function pp_excerpt(int $length = 55): string {
    return wp_trim_words(get_the_excerpt(), $length);
}

/**
 * Returns the permalink for the current post.
 */
function pp_permalink(): string {
    return (string) get_permalink();
}

/**
 * Returns the post thumbnail URL for the current post.
 *
 * @param string $size  Image size name (default 'large').
 */
function pp_thumbnail_url(string $size = 'large'): string {
    return (string) get_the_post_thumbnail_url(null, $size);
}

/**
 * Returns the composition array for the current page from _pp_composition post meta.
 * Returns an empty array when the meta is absent, empty, or contains invalid JSON.
 *
 * NO READ-PATH NAME RESOLUTION (#604). The decoded items are returned as stored.
 * The `pp_migrate_stored_composition()` shim that used to sit here is deleted: its
 * slot-name surface went in #603 and its two remaining PROP surfaces — the `variant`
 * -> `layout`/`theme` migration (#69/#388/#400) and the legacy prop-KEY alias map
 * (#495/#575/#576) — went in #604, leaving it with no body. A stored document renders
 * on the names it actually stores; a retired name is simply a prop no renderer reads,
 * so it falls back to the schema default. That is the intended outcome of the
 * vocabulary freeze, not a bug — author the canonical name.
 *
 * It does not add list-shape enforcement (that decode/classification gate is
 * pp_get_composition_result()'s job, #144, deliberately left untouched here).
 *
 * @return array  Array of component objects: [['component' => string, 'props' => array], ...]
 */
function pp_composition(): array {
    $raw = get_post_meta(get_the_ID(), '_pp_composition', true);
    if (!$raw) {
        return [];
    }
    $items = json_decode($raw, true);
    if (!is_array($items)) {
        return [];
    }
    return $items;
}

/**
 * Resolves how templates/front-page.php should render the homepage, classifying
 * the stored composition FIRST so a corrupt one is never silently overwritten (#506).
 *
 * The pre-#506 safeguard read via pp_composition() — which returns [] for BOTH a
 * genuinely-absent page AND a corrupt/undecodable one — and, on empty, seeded the
 * defaults with a RAW update_post_meta(). An anonymous view of a corrupt homepage
 * therefore DESTROYED the recoverable bytes pp_get_composition_result() (#144)
 * deliberately preserves, bypassed the versioned writer, and made inspect (reports
 * the decode error) and render (shows healthy defaults) disagree (#302). This
 * classifies before seeding.
 *
 *   pp_get_composition_result($post_id)
 *      │
 *      ├─ ok === false (decode_error / unexpected_shape)
 *      │      └─► mode 'corrupt'  — render a safe fallback, WRITE NOTHING. Stored
 *      │                            bytes stay intact; inspect remains honest.
 *      │
 *      ├─ raw === null && composition === []   (genuinely absent meta)
 *      │      ├─ $post_id <= 0 ─► mode 'no_front'  — no static front page is set.
 *      │      └─ else ─────────► seed pp_default_homepage_composition() through the
 *      │                          VERSIONED writer pp_update_composition() (version
 *      │                          marker + history ring), exactly like
 *      │                          pp_setup_homepage(); then fall through to render.
 *      │
 *      └─ otherwise ─────────► mode 'render' — a present (or just-seeded) list.
 *
 * The 'render' composition is returned as read, reproducing pp_composition()'s render
 * output byte-for-byte. The pp_normalize_legacy_props() pass that used to run here is
 * gone (#604). It was only ever load-bearing for one branch — the freshly seeded
 * pp_default_homepage_composition() — and that seed is authored in the canonical
 * vocabulary, so dropping the call changes nothing about what the homepage renders.
 *
 * A stored empty list ("[]", raw !== null) is a deliberate authored state, not an
 * absent page, so it renders blank and is NOT re-seeded — the blank-page promise
 * ("a newly created page is never blank") covers only genuinely-absent meta.
 *
 * Best-effort seed: pp_update_composition() can return a WP_Error (lock/CAS); it is
 * ignored here — a failed seed leaves the meta absent and the next render retries,
 * never a partial or lost write. This matches pp_setup_homepage()'s posture.
 *
 * @param int $post_id  The front-page post ID (0 when no static front page is set).
 * @return array{mode: string, composition: array}  mode ∈ {render, corrupt, no_front}.
 */
function pp_resolve_front_page_render(int $post_id): array {
    $result = pp_get_composition_result($post_id);

    // Corrupt / wrong-shape stored composition: never overwrite it. The raw bytes
    // are the only recovery source and inspect keeps reporting the exact error.
    if (!$result['ok']) {
        return ['mode' => 'corrupt', 'composition' => []];
    }

    $items = $result['composition'];

    // Genuinely-absent meta: no stored bytes AND no decoded items.
    if ($result['raw'] === null && $items === []) {
        if ($post_id <= 0) {
            return ['mode' => 'no_front', 'composition' => []];
        }
        // Seed once, through the versioned writer (never a raw update_post_meta),
        // then render those same defaults regardless of the write's outcome. A
        // lock/CAS WP_Error leaves the meta absent and the next render retries the
        // seed, but the visitor still gets content — the blank-page promise holds
        // even when the write is skipped, and no re-read can go stale.
        $items = pp_default_homepage_composition();
        pp_update_composition($post_id, $items);
    }

    // Present, or just-seeded: rendered exactly as read/seeded (#604 — no name
    // normalization on any read path).
    return ['mode' => 'render', 'composition' => $items];
}

// ── Site-state read functions (action-layer support) ─────────────────────────

/**
 * Compatibility shim for array_is_list() (PHP 8.1+); the plugin floor is PHP 8.0.
 *
 * A list has sequential integer keys 0..n-1. The empty array is a list. Guard
 * the empty case first — range(0, count($a) - 1) is range(0, -1) on [], which
 * yields [0], not [].
 *
 * THE FAST PATH IS THE #715 FIX, and it is here rather than at the call sites
 * because this function IS the O(N²). Every locator rendering in lib/admin.php
 * reaches _pp_item_index_label(), which asks this question ONCE PER EMITTED
 * FINDING to decide between `N` and `key "N"` — so a composition of N bad bands
 * rescans an N-element container N times. Measured on PHP 8.3, 10,000 calls over
 * a 10,000-element array: 1.0912s through the fallback below, 0.0001s through
 * array_is_list(). The engine cost that produced it went 1.0240s -> 0.0049s on
 * #715's own fixture (10,000 structurally-bad bands through the write path), and
 * 2.2573s -> 0.0565s on #654's (a 10,000-entry `items` array), with every
 * rendered message byte-identical. Scaling is linear again: 500 / 2,000 / 5,000 /
 * 10,000 bands now cost 0.0002 / 0.0008 / 0.0022 / 0.0049s.
 *
 * WHY THE BUILT-IN IS O(1) HERE — "here" being load-bearing. array_is_list()
 * short-circuits on a packed hash without holes, which is exactly what
 * json_decode() produces for a JSON array; a decoded JSON OBJECT hits a string
 * key in the first bucket and returns false just as fast. Both shapes are
 * answered without a walk, and neither allocates — the fallback below builds TWO
 * n-element arrays per call.
 *
 * It is NOT unconditionally O(1): a hash-shaped array whose keys happen to be
 * sequential ints, or a packed array holed by a mid-array unset(), makes the
 * builtin walk. No caller can hand it either — every container reaching here is a
 * decoded composition or a `props` value read straight out of one, and the only
 * composition-mutating array operations on the write path are array_splice()
 * (which repacks) and an unset() on a nested `style` key (not the container asked
 * about). Even on a walking shape the builtin measured ~18x faster than the shim,
 * so the fast path is never a regression — only its O(1) claim is shape-dependent.
 *
 * BYTE-IDENTICAL BY CONSTRUCTION, not by convention: array_is_list() and the
 * fallback are the same predicate, and PpIsListContractTest pins that they agree
 * on every shape this codebase can hand them (packed list, string-keyed object,
 * out-of-order int keys, the folded `{"0":..,"1":..}` case, and []).
 *
 * THE FALLBACK IS A FLOOR CONCESSION, NOT A SECOND IMPLEMENTATION. It runs only
 * on PHP 8.0, where it keeps #715's O(N²) — accepted by ruling: 8.0 is the
 * declared floor, has been EOL since November 2023, and CI cannot test it. When
 * the floor rises to 8.1 (style.css / readme.txt `Requires PHP`), delete this
 * function and _pp_is_list_fallback() below and call array_is_list() directly —
 * that is the knowing removal this note exists to enable.
 *
 * @param array $arr
 * @return bool
 */
function pp_is_list(array $arr): bool {
    if (PHP_VERSION_ID >= 80100) {
        return array_is_list($arr);
    }
    return _pp_is_list_fallback($arr);
}

/**
 * The PHP 8.0 arm of pp_is_list(), named so it can be TESTED (#715).
 *
 * A SEPARATE FUNCTION FOR ONE REASON: on every runtime this project can run tests
 * on, the branch above returns before this is ever reached — PHPUnit 10 requires
 * PHP 8.1+, and CI pins 8.3 — so as an inline `else` it was code that shipped to
 * the declared floor with zero test signal. Extracted, PpIsListContractTest can
 * call it DIRECTLY on 8.3 and differentially compare it against array_is_list()
 * over every array shape, which is the only way the 8.0 arm gets covered at all.
 *
 * What that coverage is worth: pp_is_list() decides the locator form in every
 * validation message AND gates pp_validate_composition_errors()'s container
 * refusal (#724, reject-never-coerce). Dropping the empty guard below would make
 * range(0, -1) return [0] rather than [], flipping `[]` from list to non-list —
 * on 8.0 that turns a valid empty composition into a refused one, and CI would
 * stay green because CI never runs this line.
 *
 * Not called anywhere but pp_is_list(). Deliberately not marked internal-only by
 * convention alone: the source tripwire in PpIsListContractTest pins the version
 * guard that routes to it, so the pair cannot drift apart silently.
 *
 * @param array $arr
 * @return bool
 */
function _pp_is_list_fallback(array $arr): bool {
    // Guard the empty case first — range(0, count($a) - 1) is range(0, -1) on [],
    // which yields [0], not [].
    if ($arr === []) {
        return true;
    }
    return array_keys($arr) === range(0, count($arr) - 1);
}

/**
 * Renders the ONE sentence every surface uses to report a corrupt stored composition (#725).
 *
 * `pp_get_composition_result()` below owns the CLASSIFICATION; this owns how that
 * classification is said out loud. Both halves have to be single-owned for the same
 * reason: #650/#652 established that one state reported in two vocabularies is how an
 * operator ends up repairing the wrong thing, and a third spelling was exactly what
 * #725 would have added by writing `inspect-composition` its own wording.
 *
 * The sentence is `wp pp check page`'s existing one, moved here VERBATIM — its bytes are
 * pinned by CompositionShapeTrustTest so routing that command through this function
 * stayed a no-op.
 *
 * WHAT IT OWNS TODAY, stated exactly, because "one classification, one sentence" is an
 * aspiration this function does not yet fully deliver and a docblock that implied
 * otherwise would license a fifth spelling:
 *
 *   OWNED   `wp pp check page`               (lib/cli.php — byte-identical, pinned)
 *   OWNED   `operate inspect-composition`    (lib/operate.php — this sentence + its own repair tail)
 *   NOT     `wp pp validate site`            names the page TITLE, which this signature cannot know
 *   NOT     `pp_post_apply_validate()`       reports "corrupted AFTER APPLY", a different claim
 *   NOT     the `operate inspect` preflight line in lib/cli.php
 *
 * The three that stay out are phrasing variants rather than a second vocabulary — each
 * already carries the classification noun (`decode_error` / `unexpected_shape`) and says
 * the row is not a valid composition list. They are left alone deliberately: routing them
 * through here would change shipped CLI output on commands this change has no business
 * touching. Widening the signature to absorb them is a follow-up, not a drive-by.
 *
 * Callers append their own next-action tail; the shared part is the diagnosis only.
 *
 * LIVES HERE, NOT IN lib/cli.php, and that is load-bearing rather than tidiness:
 * lib/cli.php returns at its own line 9 unless WP_CLI is loaded, so a builder defined
 * there would be UNDEFINED for `pp_inspect_composition()`'s other caller — the AI chat
 * context builder (lib/ai-context.php), which runs in an ordinary web request. Next to
 * the classifier is both the honest home and the only one that cannot fatal.
 *
 * @param  int    $post_id  The page whose stored composition was classified.
 * @param  string $error    The classification: 'decode_error' or 'unexpected_shape'.
 * @return string
 */
function pp_composition_integrity_message(int $post_id, string $error): string {
    return "Page {$post_id}: composition data integrity error ({$error}). "
        . 'The stored _pp_composition is not a valid composition list — treat as corrupted, not empty.';
}

/**
 * The ONE sentence every surface uses to say how a corrupt page gets repaired (#767).
 *
 * Sibling of pp_composition_integrity_message() above and single-owned for the same reason.
 * That function exists because #650/#652 established that one STATE reported in two
 * vocabularies is how an operator repairs the wrong thing; ruling D-1 makes the ROUTE
 * uniform too, so four private spellings of it are the same drift risk one layer out. They
 * had already drifted before this was extracted: of the four surfaces, only three mentioned
 * the preserved bytes and only two said the preservation happens BY the repair write — which
 * matters, because on the surfaces that refuse BEFORE any repair the ring holds nothing yet,
 * and an operator told otherwise runs `composition-history` and finds an empty list.
 *
 * Callers own their own lead-in ("the action you asked for was refused", "this proposal was
 * refused before any step ran", "PREFLIGHT was not recorded"); this owns the route.
 *
 * SCOPED TO THE NO-ROLLBACK-SNAPSHOT ROUTES, and the boundary is deliberate rather than an
 * oversight (#756). Ruling D-1 opened a third route — a chat proposal whose ONLY step is
 * one of these two verbs — and it is NOT named here, because it is not a route every caller
 * can offer. Uniformity is the point of this function, and a sentence that is true on some
 * callers' surfaces and not others is not uniform; pushing it in here to keep the spelling
 * in one place would have put wrong advice on the rest. What is single-owned is the claim
 * "a whole-composition write repairs this page, and here is what it costs"; WHERE that
 * write can be sent from is the caller's to say, in its own lead-in.
 *
 * THE CALLER CENSUS, kept current because the scoping argument above is only checkable
 * against it (six since #750, was four):
 *
 *   ROUTE IS ACTIONABLE WHERE IT RENDERS — each names it in its own lead-in
 *     _pp_batch_unreadable_target_error()   lib/actions.php   "in a proposal of its OWN"
 *     _pp_ai_page_context_corrupt_block()   lib/ai-context.php  the same, said to the model
 *     pp_composition_editor_integrity()     lib/admin.php     "replace the JSON below and save"
 *
 *   ROUTE IS ELSEWHERE — advice about a surface the reader is not on, so none is added
 *     the `apply preflight` failure          lib/cli.php
 *     the composition_required refusal       lib/operate.php
 *     pp_inspect_composition()               lib/operate.php
 *
 * The run token is optional because only one caller has one. With it the sentence renders
 * runnable commands; without it, the verbs. Never invent a token to fill the gap — a
 * copy-pasteable command carrying a fabricated UUID is worse than a named verb.
 *
 * @param  int         $post_id  The page to repair.
 * @param  string|null $run_id   The caller's run token, when it has one.
 * @return string
 */
function pp_corrupt_repair_route_message(int $post_id, ?string $run_id = null): string {
    if ($run_id !== null) {
        $route = 'Repair it with ONE whole-composition write, which needs no preflight on a page in'
            . ' this state (#767): `wp pp action execute update_composition --run-id=' . $run_id
            . ' --params=\'{"post_id":' . $post_id . ',"composition":[...]}\'` (a JSON array of'
            . ' components), or `wp pp action execute restore_composition --run-id=' . $run_id
            . ' --params=\'{"post_id":' . $post_id . '}\'`.';
    } else {
        $route = 'Repair it with ONE whole-composition write — `update_composition` (a JSON array'
            . ' of components) or `restore_composition` — which on WP-CLI (`wp pp action execute`)'
            . ' needs no preflight on a page in this state (#767); the dashboard composition editor'
            . ' does the same job.';
    }

    // FUTURE TENSE, DELIBERATELY. Every caller of this renders BEFORE any repair has run, so
    // the ring does not hold these bytes yet. "The bytes are preserved" sends an operator to
    // an empty history listing and teaches them the message lies.
    return $route
        . ' The repair write preserves the unreadable bytes on the page\'s history ring (#818);'
        . ' read them afterwards with `wp pp operate composition-history --post_id=' . $post_id . '`.';
}

/**
 * Reads _pp_composition and classifies its state, so callers can tell a
 * genuinely blank page apart from a corrupted one (issue #144).
 *
 * Return shape: ['ok' => bool, 'composition' => array, 'error' => ?string, 'raw' => ?string].
 *
 * State classification, checked in this exact order:
 *
 *   absent / blank meta ('' / falsy)   ok=true  composition=[]     error=null               raw=null
 *   already-decoded list (fixture)     ok=true  composition=$raw   error=null               raw=null
 *   already-decoded NON-list array     ok=false composition=[]     error='unexpected_shape' raw=null
 *   non-string scalar meta (int/bool)  ok=false composition=[]     error='unexpected_shape' raw=null
 *   undecodable JSON string            ok=false composition=[]     error='decode_error'     raw=<raw>
 *   valid JSON, non-list (obj/scalar)  ok=false composition=[]     error='unexpected_shape' raw=<raw>
 *   valid JSON list ([] or [ ... ])    ok=true  composition=$items error=null               raw=<raw>
 *
 * A JSON object decodes to an associative PHP array that is_array() would accept,
 * so list-shape is enforced via pp_is_list() to keep objects out of compositions.
 *
 * This is the single owner of the CACHED read every consumer shares (inspect / check /
 * validate, and the front-page render safeguard pp_resolve_front_page_render() — issue
 * #506 — which classifies here so a corrupt homepage is never overwritten by the
 * blank-page seed). Since #767 the CLASSIFICATION half is owned one level down by
 * pp_classify_composition_value(), so that a second READER can exist without a second
 * spelling of the decision; #144's "one classifier" invariant lives there now and is
 * unweakened. There are exactly two readers and adding a third needs a reason:
 * this one (cached, for every consumer) and pp_get_composition_result_authoritative()
 * (uncached, for gate-opening decisions only). The generic renderer
 * pp_composition() still keeps its own defensive decode by design — issue #144 leaves
 * that render path untouched.
 *
 * SINCE #767 THE READ AND THE CLASSIFICATION ARE SEPARATE FUNCTIONS, and the split is
 * the point rather than tidiness. This function is still the ONLY thing that pairs "read
 * `_pp_composition` the way every consumer reads it" with the classification, and its
 * behaviour is byte-for-byte what it was. What the split buys is a SECOND reader —
 * pp_get_composition_result_authoritative() below — that answers the same question from
 * the database instead of the object cache, without forking the classification into a
 * second spelling. #144's "one classify owner" invariant now lives in
 * pp_classify_composition_value(); both readers delegate to it.
 *
 * @param int $post_id  WordPress post ID.
 * @return array{ok: bool, composition: array, error: ?string, raw: ?string}
 */
function pp_get_composition_result(int $post_id): array {
    return pp_classify_composition_value(get_post_meta($post_id, '_pp_composition', true));
}

/**
 * The $wpdb handle, but only when it can actually answer an uncached composition read (#767).
 *
 * SINGLE OWNER OF THE CAPABILITY QUESTION, because callers need the SAME answer for
 * opposite reasons and must never disagree about it. Five consumers, three READERS that
 * degrade and two GATES that refuse:
 *
 *   pp_get_composition_result_authoritative()  falls back to the cached read when this is
 *                                              null — an honest degradation for a general
 *                                              reader with no database to ask.
 *   _pp_read_composition_history_locked()      the same degradation, inside the write lock
 *                                              (#823): a ring it cannot read authoritatively
 *                                              is still better read stale than treated as
 *                                              empty, which would wipe it.
 *   _pp_read_composition_hash_locked()         the same degradation again, on the ring
 *                                              ENTRY's provenance marker (#828). The mildest
 *                                              of the three: the cached read it falls back
 *                                              to is exactly what that slot held before
 *                                              #828, so degrading here cannot be worse than
 *                                              the behaviour it replaced.
 *   pp_corrupt_page_repair_carve_out()         refuses to open when this is null — because
 *                                              a GATE may not be opened by a value that
 *                                              might be stale, and the cached read is
 *                                              exactly the value it might be.
 *   _pp_batch_target_refusal_reason()          the same posture since #833, on the other
 *                                              half of the same gate: it blocks the target
 *                                              (PP_BATCH_TARGET_UNVERIFIABLE) rather than
 *                                              classify it from a value that might be stale.
 *
 * That asymmetry is the point, and it is why the check lives here rather than inline in
 * either: if the reader silently degraded while the gate kept trusting it, the cache-staleness
 * hazard the carve-out was built to avoid would come straight back through the fallback.
 *
 * Four clauses, all required, because a partial handle is a real shape in this codebase's
 * unit context (several test doubles implement get_var and nothing else) and reading
 * `$wpdb->postmeta` off one of them yields an undefined-property warning and an empty table
 * name — a query that cannot be trusted. In production WordPress every clause always holds.
 *
 * @return object|null  The handle, or null when an uncached read is not possible.
 */
function pp_composition_db_handle(): ?object {
    global $wpdb;

    if (is_object($wpdb)
        && method_exists($wpdb, 'get_var')
        && method_exists($wpdb, 'prepare')
        && isset($wpdb->postmeta)) {
        return $wpdb;
    }
    return null;
}

/**
 * The same question, answered from the DATABASE rather than the object cache (#767).
 *
 * WHY THIS EXISTS, and it is a security boundary rather than a performance note.
 * The corrupt-page repair carve-out (#767, maintainer ruling D-1) NARROWS an enforcement
 * gate: on a page whose stored composition is classified corrupt, `update_composition` /
 * `restore_composition` are admitted without a covering preflight on WP-CLI, and (since
 * #756) admitted through the #749 chat batch refusal when they travel alone. The input
 * that opens that gate is therefore the classification itself, and a gate must not be
 * openable by a value that is merely STALE.
 *
 * It is reachable, not theoretical. #823 documents the mechanism on the sibling meta:
 * WordPress warms a post's WHOLE meta row in one query (update_meta_cache), so any earlier
 * get_post_meta() on the post populates `_pp_composition` in the request-local cache too,
 * and a concurrent repair landing after that warm-up leaves the cached copy corrupt while
 * the row is healthy. Reading the cache there would admit a preflight-free write to a
 * HEALTHY page — precisely the thing the carve-out is not allowed to do.
 *
 * TWO THINGS MAKE THE READERS AGREE, and both are load-bearing rather than decoration.
 * Anywhere they disagree, the disagreement IS the vulnerability: every other surface —
 * `wp pp check page`, `apply preflight`, the composition precondition — classifies through
 * the cached reader, so a row this one calls corrupt and they call healthy opens the
 * carve-out on a page the coverage gate considers perfectly preflightable.
 *
 * THE #749 BATCH GATE READS THE ROW ON BOTH SIDES SINCE #833, and the history is worth
 * keeping because the intermediate state looked reasonable and was not. Until #833 the
 * refusal half (`_pp_batch_unreadable_targets()`, and the snapshotter's own copy)
 * classified through the CACHED reader while the exemption half
 * (`_pp_batch_corrupt_repair_admitted()`) reached this one. The exemption required BOTH to
 * call the page corrupt, so the EXEMPTION could only ever fail closed — but the REFUSAL
 * could not, and that was the hole: with a stale healthy cache over a corrupt row the
 * cached map came back empty, so there was no refusal to lift and the batch simply ran.
 *
 * Since #833 both halves require this reader (`_pp_batch_target_refusal_reason()`, the
 * gate's single source owner), and both fail closed when pp_composition_db_handle() returns
 * null. The refusal ALSO keeps its pre-existing cached check, as an added requirement
 * rather than an alternative source — a target must read clean BOTH ways — so:
 *
 *   cache corrupt, row healthy   refused, exactly as before (#756 pins this direction).
 *   cache healthy, row corrupt   refused; this is the direction #833 closed.
 *   no usable handle             refused on both halves; nothing may be admitted unread.
 *
 * The #756 exemption still sits on top of that table rather than inside it: a lone
 * update_composition / restore_composition step on a page this reader calls corrupt is
 * ADMITTED, in row two as much as anywhere else. That is the point of reading the row on
 * both sides — the refusal and the exemption now name the same page for the same reason,
 * where before row two produced neither.
 *
 * "Agreement" is therefore no longer a reconciliation of two verdicts about what is
 * stored: the row decides that, and the cached read survives only as a second condition
 * that can add a refusal and never remove one. What that buys is stated where it is
 * relied upon — the snapshotter captures its rollback baseline from the cached read, so a
 * healthy verdict has to mean the cached read was healthy too, or the baseline could be
 * the degrading accessor's `[]` under a map that says fine.
 *
 *   maybe_unserialize()   get_post_meta() unserializes on the way out (get_metadata ->
 *                         maybe_unserialize), a direct column read does not. `_pp_composition`
 *                         normally holds JSON, but nothing enforces that: any caller passing
 *                         an ARRAY to update_post_meta() — an importer, a post-duplicator
 *                         plugin, `wp post meta update <id> _pp_composition '[...]'
 *                         --format=json` — stores a PHP-serialized row. Raw, that is
 *                         `a:1:{...}`, which is not JSON, which classifies `decode_error`
 *                         while every other surface reads a healthy list. maybe_unserialize()
 *                         is a no-op on the JSON rows this theme writes, so it costs nothing
 *                         and closes that gap. The sibling authoritative reader
 *                         _pp_read_token_overrides_locked_strict() takes the same step for
 *                         the same reason.
 *   ORDER BY meta_id ASC  `_pp_composition` is single-valued by convention, not by schema.
 *                         get_post_meta($id, $k, true) returns the FIRST row in meta_id order
 *                         (update_meta_cache selects ORDER BY meta_id ASC, get_metadata takes
 *                         [0]). An unordered SELECT ... LIMIT 1 may hand back a different row,
 *                         so a duplicated key could open the carve-out on the strength of a
 *                         row no other surface reads.
 *
 * RELATED BUT NOT THE SAME as the four `_locked` readers below (_pp_read_composition_json_locked,
 * _pp_read_composition_version_locked, _pp_read_composition_history_locked,
 * _pp_read_composition_hash_locked). All five read the row instead of the cache; the
 * difference is only WHERE they run — those four inside pp_update_composition()'s advisory
 * lock, this one outside it, at a gate. Since #825/#828 all five share the row-ordering
 * rule too: the version and json readers issued an unordered LIMIT 1 until #825, and the
 * ring entry's hash was a cached read until #828.
 *
 * WHAT THEY STILL DO NOT ALL SHARE is the #212 failed-read disambiguation and this
 * function's four-clause capability check. The history and hash readers have both; the
 * version and json readers have neither, so a failed SELECT reads as "absent" there and a
 * partial handle takes their DB branch. Pre-existing, named in each of their docblocks,
 * and filed (#848, #849) rather than widened inside an ordering fix.
 *
 * FALLBACK, stated plainly: with no usable handle (see pp_composition_db_handle) this
 * degrades to the cached read, because a reader with no database to ask has nothing better
 * to return. Production WordPress always has one, so the fallback is a unit-context path,
 * never a live one — and the carve-out's own tests install a $wpdb stub precisely so they
 * exercise the authoritative branch rather than quietly proving the fallback.
 *
 * THE CARVE-OUT DOES NOT INHERIT THAT FALLBACK, and that is deliberate: it refuses to open
 * rather than trust a cached answer. Degrading is right for a reader and wrong for a gate.
 *
 * @param int $post_id  WordPress post ID.
 * @return array{ok: bool, composition: array, error: ?string, raw: ?string}
 */
function pp_get_composition_result_authoritative(int $post_id): array {
    $wpdb = pp_composition_db_handle();

    if ($wpdb !== null) {
        $raw = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s ORDER BY meta_id ASC LIMIT 1",
                $post_id,
                '_pp_composition'
            )
        );
        // A missing row is null here and '' from get_post_meta(single=true); the classifier
        // treats both as genuine absence, so the two readers agree on a blank page.
        // maybe_unserialize() is the step get_metadata() takes on the cached path — see the
        // docblock: without it a PHP-serialized row reads `decode_error` here and healthy
        // everywhere else, which is the disagreement that OPENS the gate.
        return pp_classify_composition_value($raw === null ? '' : maybe_unserialize($raw));
    }

    return pp_get_composition_result($post_id);
}

/**
 * Is this stored `_pp_composition` value a genuinely BLANK page? (#144, extracted #750)
 *
 * The one owner of "blank", for the same reason the classification and the two sentences
 * are single-owned: a surface that decides this for itself decides it differently one day.
 * The composition editor needs the same answer (a blank pane belongs to a blank page, and
 * to nothing else — lib/admin.php), and a hand-copied `=== '' || === null || === false`
 * there would be kept in sync by prose alone.
 *
 * Absent meta: get_post_meta(single=true) returns '' when the key does not exist. Match only
 * genuine absence — NOT every falsy value — so a stored falsy-but-present payload (the JSON
 * string "0", an int 0) still reaches shape classification instead of masquerading as a
 * blank page.
 *
 * @param mixed $raw  The stored value, exactly as read.
 * @return bool
 */
function pp_composition_value_is_absent($raw): bool {
    return $raw === '' || $raw === null || $raw === false;
}

/**
 * The classification itself, as a pure function of the stored value (#144, extracted #767).
 *
 * Every state table entry documented on pp_get_composition_result() is decided HERE. Two
 * readers feed it — the cached one and the authoritative one — so a caller that needs a
 * database-fresh answer never has to re-implement the decision. Keep it pure: it must be
 * callable on a value the caller already holds (a $wpdb column, a fixture, a decoded array)
 * without touching post meta.
 *
 * @param mixed $raw  The stored `_pp_composition` value, exactly as read.
 * @return array{ok: bool, composition: array, error: ?string, raw: ?string}
 */
function pp_classify_composition_value($raw): array {
    if (pp_composition_value_is_absent($raw)) {
        return ['ok' => true, 'composition' => [], 'error' => null, 'raw' => null];
    }

    // Defensive: a caller/fixture may have persisted an already-decoded array.
    // Enforce the same list-shape as the JSON path so a decoded object (an
    // associative array) is flagged, not silently accepted.
    if (is_array($raw)) {
        if (!pp_is_list($raw)) {
            return ['ok' => false, 'composition' => [], 'error' => 'unexpected_shape', 'raw' => null];
        }
        return ['ok' => true, 'composition' => $raw, 'error' => null, 'raw' => null];
    }

    // Normal storage is a JSON string. A truthy non-string scalar (int, float,
    // bool) is neither a composition nor a JSON payload we should decode.
    if (!is_string($raw)) {
        return ['ok' => false, 'composition' => [], 'error' => 'unexpected_shape', 'raw' => null];
    }

    $items = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        // Truncated write, encoding bug, malformed UTF-8: undecodable.
        return ['ok' => false, 'composition' => [], 'error' => 'decode_error', 'raw' => $raw];
    }
    if (!is_array($items) || !pp_is_list($items)) {
        // Valid JSON but the wrong shape: object, scalar, or literal null.
        return ['ok' => false, 'composition' => [], 'error' => 'unexpected_shape', 'raw' => $raw];
    }

    return ['ok' => true, 'composition' => $items, 'error' => null, 'raw' => $raw];
}

/**
 * Returns the composition array for a specific page by post ID.
 * Unlike pp_composition(), this works outside the loop.
 *
 * Legacy array-only accessor: delegates to pp_get_composition_result() (the
 * single decode owner) and returns only the items. A corrupt or non-list row
 * degrades to [] here — callers that need to distinguish corruption from an
 * empty page use pp_get_composition_result() directly.
 *
 * @param int $post_id  WordPress post ID.
 * @return array  Array of component objects, or [] if absent/invalid.
 */
function pp_get_composition(int $post_id): array {
    return pp_get_composition_result($post_id)['composition'];
}

/**
 * The menu locations the base template renders on every page.
 *
 * Mirrors the pp_get_component() calls in templates/base.php:
 *
 *   templates/base.php:35   pp_get_component('nav',    ['location' => 'primary'])
 *   templates/base.php:49   pp_get_component('footer', ['location' => 'footer'])
 *
 * Chrome is template-owned, not composition-declared (#223), so this is the only
 * honest source for "which locations does this site actually render?".
 * NavReadinessTest::testTemplateOwnedLocationsMatchBaseTemplate() reads the
 * template back and fails if the two ever drift.
 *
 * @return string[]  Registered nav-menu location slugs the template renders.
 */
function pp_template_owned_menu_locations(): array {
    return ['primary', 'footer'];
}

/**
 * The menu locations the base template renders CONDITIONALLY (#582).
 *
 * A deliberate sibling of pp_template_owned_menu_locations(), not an extension of
 * it. The two lists answer different questions and must never be merged:
 *
 *   pp_template_owned_menu_locations()      "renders on EVERY page"
 *     -> unregistered / unassigned / empty are all findings
 *
 *   pp_conditionally_rendered_menu_locations()   "renders ONLY when assigned"
 *     -> unassigned is the DEFAULT, never a finding; only an assigned-but-broken
 *        menu is worth a word
 *
 * Today that is `footer_secondary` alone (templates/base.php passes it as the
 * footer's `secondary_location`, functions.php registers it, and the column renders
 * only behind has_nav_menu()). Adding it to the template-owned list instead would
 * emit a readiness row on every site that never assigned that menu — precisely the
 * noise pp_check_nav_readiness()'s docstring rules out by name.
 *
 * Kept separate from the template-owned list for a second reason: that list is
 * drift-guarded against the FIRST `'location' =>` key of each pp_get_component()
 * call in templates/base.php (NavReadinessTest), and `secondary_location` is
 * deliberately not that key. This list carries its own drift pin instead.
 *
 * @return string[]  Registered nav-menu location slugs rendered only when assigned.
 */
function pp_conditionally_rendered_menu_locations(): array {
    return ['footer_secondary'];
}

/**
 * Whether the menu assigned to a location resolves to zero renderable items (#582).
 *
 * Extracted so the always-on loop and the conditional loop in
 * pp_check_nav_readiness() cannot drift on what "empty" means. Both previously
 * inlined this predicate; a shared helper makes the docblock's claim structural
 * rather than a promise in prose.
 *
 * `false` from wp_get_nav_menu_items() (an unresolvable menu id) and `[]` (a real
 * but empty menu) are the same finding: the location paints nothing either way.
 *
 * @param string $location   Theme location slug.
 * @param array  $locations  get_nav_menu_locations() result: location => menu_id.
 * @return bool  True when the location's menu yields no items.
 */
function pp_menu_location_is_empty(string $location, array $locations): bool {
    $menu_id = $locations[$location] ?? 0;
    $items   = $menu_id ? wp_get_nav_menu_items($menu_id) : false;
    return empty($items);
}

/**
 * Diagnoses readiness of the site chrome the base template renders.
 *
 * Scoped to the TEMPLATE-OWNED locations (pp_template_owned_menu_locations()),
 * not to anything a page composition declares — chrome is not composable (#223).
 * Registered-but-unrendered locations (e.g. one a plugin adds) are intentionally
 * NOT flagged; that would be noise about markup this theme never emits.
 *
 * For each rendered location it flags, in order:
 *   - an UNREGISTERED location (the template renders a location nobody declared),
 *   - a registered location with NO menu assigned,
 *   - an assigned menu that resolves to ZERO items.
 * It additionally flags a site logo option that does not resolve to an image
 * attachment, which pp_resolve_logo() silently falls back to a text wordmark for.
 *
 * A ready location reports a passing row. Every row is severity=warning: chrome
 * readiness is an operator-facing diagnostic, never a gate on content mutations.
 *
 * CONDITIONALLY rendered locations (#582) are diagnosed too, but under an inverted
 * rule: pp_conditionally_rendered_menu_locations() names locations the footer paints
 * ONLY when a menu is assigned, so "no menu assigned" is the intended default and
 * emits nothing at all. The single state worth a word is an ASSIGNED menu that
 * resolves to zero items — the operator did the work of assigning it and gets a
 * silently missing column. A healthy one reports NOTHING rather than a passing row,
 * for the same reason the site-logo check below reports nothing when no logo is set:
 * an optional-by-design surface must not leave a standing row on every site that
 * uses it. Emptiness is judged with the identical wp_get_nav_menu_items() test the
 * template-owned loop uses, so the two surfaces cannot drift on what "empty" means.
 *
 * @return array[]  Rows: ['check'=>'nav_readiness','pass'=>bool,'severity'=>'warning','message'=>string].
 *                  Non-passing rows are configuration-class findings (#496) and
 *                  additionally carry 'class'=>'configuration', a stable
 *                  'finding_key', 'acknowledgeable'=>true, and a 'next_action'.
 */
function pp_check_nav_readiness(): array {
    $registered = array_keys(get_registered_nav_menus());
    $locations  = get_nav_menu_locations(); // location => menu_id
    $checks     = [];

    foreach (pp_template_owned_menu_locations() as $loc) {
        // Messages may be rendered in the admin UI. $loc is a theme constant now
        // rather than AI-controlled composition data, but escaping it costs
        // nothing and keeps the guarantee local. Raw $loc is used for lookups.
        $safe_loc = esc_html((string) $loc);

        // Configuration-class findings (#496): each carries a stable, generic
        // finding_key (keyed on the template-owned location constant, never on
        // site data) and a sanctioned next action, and is acknowledgeable — an
        // operator can record a deliberately menu-less location as intentional.
        if (!in_array($loc, $registered, true)) {
            $checks[] = ['check' => 'nav_readiness', 'pass' => false, 'severity' => 'warning',
                'class'           => 'configuration',
                'finding_key'     => 'nav_readiness:' . $loc . ':unregistered',
                'acknowledgeable' => true,
                'next_action'     => 'Register the "' . $safe_loc . '" menu location in functions.php (or acknowledge as intentional).',
                'message' => 'The page template renders menu location "' . $safe_loc . '", which is not registered. Register it in functions.php.'];
            continue;
        }
        if (!has_nav_menu($loc)) {
            $checks[] = ['check' => 'nav_readiness', 'pass' => false, 'severity' => 'warning',
                'class'           => 'configuration',
                'finding_key'     => 'nav_readiness:' . $loc . ':no_menu',
                'acknowledgeable' => true,
                'next_action'     => 'Assign a menu to "' . $safe_loc . '" via the set_menu action (or Appearance → Menus), or acknowledge as intentional.',
                'message' => 'Site chrome location "' . $safe_loc . '" has no menu assigned. Use the set_menu action (or Appearance → Menus) to create one and assign it (issue 132).'];
            continue;
        }
        if (pp_menu_location_is_empty($loc, $locations)) {
            $checks[] = ['check' => 'nav_readiness', 'pass' => false, 'severity' => 'warning',
                'class'           => 'configuration',
                'finding_key'     => 'nav_readiness:' . $loc . ':empty_menu',
                'acknowledgeable' => true,
                'next_action'     => 'Add links to the "' . $safe_loc . '" menu via set_menu or add_menu_item (or Appearance → Menus), or acknowledge as intentional.',
                'message' => 'The menu assigned to site chrome location "' . $safe_loc . '" is empty. Use the set_menu or add_menu_item action (or Appearance → Menus) to add links (issue 132).'];
            continue;
        }
        $items = wp_get_nav_menu_items($locations[$loc] ?? 0);
        // Healthy: not a finding, no class.
        $checks[] = ['check' => 'nav_readiness', 'pass' => true, 'severity' => 'warning',
            'message' => 'Site chrome location "' . $safe_loc . '" is ready (' . count($items) . ' item(s)).'];
    }

    // Conditionally rendered locations (#582): fire ONLY on an assigned-but-empty menu.
    //
    //   no menu assigned  -> nothing (the default; the column simply is not rendered)
    //   assigned + items  -> nothing (no passing row — see the docblock)
    //   assigned + EMPTY  -> one warning row, the state that is otherwise silent
    //
    // has_nav_menu() is the gate because it is the same predicate footer.php renders
    // behind, so this diagnostic can never warn about a column the theme did not emit.
    // WordPress's has_nav_menu() already requires the location to be registered, which
    // is why there is no unregistered branch here: an unregistered location is
    // unassignable, so it cannot reach this check at all.
    foreach (pp_conditionally_rendered_menu_locations() as $loc) {
        if (!has_nav_menu($loc)) {
            continue;
        }
        if (!pp_menu_location_is_empty($loc, $locations)) {
            continue;
        }
        // Escaped for the same reason as the loop above: these messages may render in
        // the admin UI. $loc is a theme constant, not AI-controlled composition data.
        $safe_loc = esc_html((string) $loc);
        // The message says "an empty column", not "no column": footer.php gates the
        // secondary <nav> on has_nav_menu() alone, which is TRUE for an assigned-but-
        // empty menu, so the landmark and its heading do render — with nothing in them.
        // Reporting it as invisible would send the operator looking in the wrong place.
        $checks[] = ['check' => 'nav_readiness', 'pass' => false, 'severity' => 'warning',
            'class'           => 'configuration',
            'finding_key'     => 'nav_readiness:' . $loc . ':empty_menu',
            'acknowledgeable' => true,
            'next_action'     => 'Add links to the "' . $safe_loc . '" menu via set_menu or add_menu_item (or Appearance → Menus), or unassign the menu from the location to drop the column.',
            'message' => 'A menu is assigned to the optional site chrome location "' . $safe_loc
                . '", but it is empty, so the footer renders an empty column. Use the set_menu '
                . 'or add_menu_item action (or Appearance → Menus) to add links, or unassign the '
                . 'menu from this location to drop the column deliberately (issue 582).'];
    }

    // Site logo: the only chrome surface reachable without the menu API. An id that
    // isn't an image attachment makes pp_resolve_logo() fall through to the text
    // wordmark silently (#155), so an operator who set the option sees no logo and
    // no explanation.
    //
    // Only a POSITIVE id means "the operator set a logo". '' / '0' / 0 / false all
    // mean cleared — 0 is WordPress's conventional cleared attachment id — and a
    // cleared logo is a deliberate text wordmark, not a finding. Testing `!== ''`
    // here would warn forever on a cleared option, and the message would read
    // "attachment 0, which is not an image". Report nothing rather than a passing
    // row: a site with no logo should not carry a standing chrome warning.
    $logo_option = (int) get_option('pp_logo_id', '');
    if ($logo_option > 0 && !pp_is_image_attachment($logo_option)) {
        $checks[] = ['check' => 'nav_readiness', 'pass' => false, 'severity' => 'warning',
            'class'           => 'configuration',
            'finding_key'     => 'nav_readiness:logo:not_image',
            'acknowledgeable' => true,
            'next_action'     => 'Set pp_logo_id to an image attachment id, clear it to use the wordmark, or acknowledge the wordmark fallback as intentional.',
            'message' => 'The "pp_logo_id" site option is set to attachment ' . $logo_option
                . ', which is not an image. The site chrome falls back to a text wordmark. '
                . 'Set pp_logo_id to an image attachment id, or clear it to use the wordmark deliberately.'];
    }

    return $checks;
}

/**
 * Returns every navigation menu with its assigned theme location (if any)
 * and item summaries — the AI-visible surface for grounding menu proposals
 * against real state (issue 132), mirroring pp_composition_pages()'s role
 * for pages.
 *
 * @return array[]  Each: ['id'=>int, 'name'=>string, 'location'=>?string, 'items'=>[['title'=>string,'url'=>string], ...]]
 */
function pp_get_menus(): array {
    $menus = wp_get_nav_menus();
    $locations = array_flip(array_filter(get_nav_menu_locations())); // menu_id => location, unassigned (0) dropped

    $result = [];
    foreach ($menus as $menu) {
        $items = wp_get_nav_menu_items($menu->term_id);
        $result[] = [
            'id'       => $menu->term_id,
            'name'     => $menu->name,
            'location' => $locations[$menu->term_id] ?? null,
            'items'    => array_map(function ($item) {
                return ['title' => $item->title, 'url' => $item->url];
            }, $items ?: []),
        ];
    }
    return $result;
}

/**
 * Creates a navigation menu (issue 132).
 *
 * @param  string $name  Menu name.
 * @return int|WP_Error  New menu (term) ID, or the WP_Error wp_create_nav_menu()
 *                        itself returns (e.g. on a duplicate name).
 */
function pp_create_nav_menu(string $name) {
    return wp_create_nav_menu($name);
}

/**
 * Adds a single item to a navigation menu (issue 132) — a page link
 * (page_id) or a custom link (url + label).
 *
 * Named page_id, not post_id: the operate/preflight gate treats a
 * top-level `post_id` action param as "this action mutates that specific
 * post," requiring a PREFLIGHT covering it — but add_menu_item's linked
 * page is data referenced by a site-scoped mutation (the menu), not the
 * post being mutated, so it must not collide with that param name.
 *
 * @param  int   $menu_id  Menu (term) ID.
 * @param  array $item     ['page_id' => int] or ['url' => string, 'label' => string];
 *                         optional 'position' => int, optional 'parent_id' => int
 *                         (the item id of the parent menu item, for one-level
 *                         dropdown children — issue 381).
 * @return int|WP_Error    New menu item ID, or WP_Error on failure.
 */
function pp_add_nav_menu_item(int $menu_id, array $item) {
    $args = ['menu-item-status' => 'publish'];

    if (!empty($item['page_id'])) {
        $args['menu-item-type']      = 'post_type';
        $args['menu-item-object']    = 'page';
        $args['menu-item-object-id'] = (int) $item['page_id'];
    } else {
        $args['menu-item-type']  = 'custom';
        $args['menu-item-url']   = $item['url'] ?? '';
        $args['menu-item-title'] = $item['label'] ?? '';
    }

    if (isset($item['position'])) {
        $args['menu-item-position'] = (int) $item['position'];
    }

    // A non-zero parent id nests this item under an existing top-level item as
    // a dropdown child (issue 381). WordPress stores it as the item's
    // menu_item_parent, which the snapshot/rollback path already round-trips.
    if (!empty($item['parent_id'])) {
        $args['menu-item-parent-id'] = (int) $item['parent_id'];
    }

    return wp_update_nav_menu_item($menu_id, 0, $args);
}

/**
 * Removes every item from a menu, leaving the menu itself intact — used by
 * the set_menu action's replace semantics (issue 132).
 *
 * @param int $menu_id  Menu (term) ID.
 */
function pp_clear_nav_menu_items(int $menu_id): void {
    $items = wp_get_nav_menu_items($menu_id);
    foreach ($items ?: [] as $item) {
        wp_delete_post($item->ID, true);
    }
}

/**
 * Assigns a menu to a registered theme navigation location (issue 132).
 *
 * @param  int    $menu_id   Menu (term) ID.
 * @param  string $location  Registered theme location slug (e.g. 'primary').
 * @return bool
 */
function pp_assign_menu_location(int $menu_id, string $location): bool {
    $locations = get_theme_mod('nav_menu_locations', []);
    if (!is_array($locations)) {
        $locations = [];
    }
    $locations[$location] = $menu_id;
    return set_theme_mod('nav_menu_locations', $locations);
}

/**
 * Returns all pages using the Composition template.
 * Each entry: ['id' => int, 'title' => string, 'status' => string, 'url' => string].
 * URL is get_permalink() for all statuses (best available WP link, not guaranteed public for drafts).
 * Uses static cache — safe to call multiple times per request.
 *
 * @return array
 */
function pp_composition_pages(): array {
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $posts = get_posts([
        'post_type'      => 'page',
        'post_status'    => ['publish', 'draft', 'pending', 'private'],
        'meta_key'       => '_wp_page_template',
        'meta_value'     => 'composition.php',
        'posts_per_page' => -1,
        'orderby'        => 'title',
        'order'          => 'ASC',
    ]);

    $cache = [];
    foreach ($posts as $post) {
        $cache[] = [
            'id'     => $post->ID,
            'title'  => $post->post_title,
            'status' => $post->post_status,
            'url'    => (string) get_permalink($post->ID),
        ];
    }

    return $cache;
}

/**
 * Returns CSS custom properties from base.css :root {} with type metadata.
 * Each token is ['value' => string, 'type' => string|null].
 * Type is extracted from the structured comment convention: /* type: description *​/
 *
 * Returns e.g. ['--color-bg' => ['value' => '#ffffff', 'type' => 'color'], ...].
 * Static cached. Call pp_invalidate_design_tokens_cache() after writes.
 *
 * @return array  Associative array of CSS custom property name => ['value', 'type'].
 */
function pp_design_tokens(): array {
    static $cache = null;
    if (!empty($GLOBALS['_pp_design_tokens_invalidate'])) {
        $cache = null;
        unset($GLOBALS['_pp_design_tokens_invalidate']);
    }
    if ($cache !== null) {
        return $cache;
    }

    $file = get_template_directory() . '/assets/css/base.css';
    if (!file_exists($file)) {
        $cache = [];
        return $cache;
    }

    $css = file_get_contents($file);
    $cache = [];

    // Match :root { ... } block
    if (preg_match('/:root\s*\{([^}]+)\}/s', $css, $root_match)) {
        // Match each --property: value; with optional /* type: description */ comment
        preg_match_all(
            '/(--[\w-]+)\s*:\s*([^;]+);\s*(?:\/\*\s*(\w[\w-]*):\s*[^*]*\*\/)?/',
            $root_match[1],
            $matches,
            PREG_SET_ORDER
        );
        foreach ($matches as $m) {
            $name  = trim($m[1]);
            $value = trim($m[2]);
            $type  = isset($m[3]) && $m[3] !== '' ? $m[3] : null;
            $cache[$name] = ['value' => $value, 'type' => $type];
        }
    }

    // Merge database overrides: override values replace defaults, types preserved.
    $overrides = pp_get_token_overrides();
    foreach ($overrides as $token => $override_value) {
        if (isset($cache[$token])) {
            $cache[$token]['value'] = $override_value;
        }
    }

    return $cache;
}

/**
 * Invalidates the pp_design_tokens() static cache.
 * Call after modifying token overrides so subsequent reads return fresh data.
 */
function pp_invalidate_design_tokens_cache(): void {
    // Static variables can only be reset by re-calling the function
    // with a flag. We use a global flag that pp_design_tokens() checks.
    $GLOBALS['_pp_design_tokens_invalidate'] = true;
}

/**
 * Returns the declared style slots for a component type.
 *
 * Reads from the cached schema registry (pp_get_registered_components).
 * Each slot has: type, default, description.
 *
 * @param string $component_name  Component name, e.g. 'hero'.
 * @return array  Associative array of slot name => definition, or empty array if component has no slots.
 */
function pp_get_style_slots(string $component_name): array {
    $components = pp_get_registered_components();
    if (!isset($components[$component_name])) {
        return [];
    }
    return $components[$component_name]['styling']['style_slots'] ?? [];
}

/**
 * The item-scoped subset of a component's declared style slots (issue 323's
 * `item_eligible` flag), extracted in #579 so write and render read ONE predicate.
 *
 * `item_eligible` marked a slot as renderable on a single element of an array prop
 * (a grid card, a section panel row). Until #579 it was enforced at WRITE only
 * (_pp_validate_style_slot_map, lib/admin.php) and the renderer took no item-scope
 * parameter at all — so a container-scoped slot that reached storage by a
 * NON-validating path (a raw meta write, or `restore_composition`, which by ruling
 * never blocks) was emitted on the `<li>` anyway. section.php already carried the
 * comment "the renderer only echoes item_eligible slots (issue 323)" describing
 * behaviour it did not have.
 *
 * Grid is the larger surface by an order of magnitude — 20 of its 37 slots are
 * item-eligible against section's 1 — so fixing section alone would have left the
 * component the feature was built for still leaking.
 *
 * @param  array $slots  A component's declared style_slots.
 * @return array         The subset carrying `item_eligible`; empty when none do.
 */
function pp_item_eligible_slots(array $slots): array {
    return array_filter(
        $slots,
        static fn ($def) => is_array($def) && !empty($def['item_eligible'])
    );
}

/**
 * Returns the declared style recipes for a component type.
 *
 * @param string $component_name  Component name, e.g. 'hero'.
 * @return array  Associative array of recipe name => definition, or empty array.
 */
function pp_get_style_recipes(string $component_name): array {
    $components = pp_get_registered_components();
    if (!isset($components[$component_name])) {
        return [];
    }
    return $components[$component_name]['styling']['recipes'] ?? [];
}

/**
 * Render-time validation gate for a stored style value (issue #330).
 *
 * The write-time engines strictly validate style values, but two paths can put
 * a value into storage that never passed (current) validation: snapshot restore
 * — which is intentionally never blocked by current rules (#233) — and
 * out-of-band DB writes (direct SQL, import tooling, another plugin). This gate
 * is the defense-in-depth check at the render boundary, immediately before a
 * value is emitted into an inline `style=""` attribute. It changes nothing about
 * write-time or restore semantics: a rejected value is simply dropped from the
 * rendered output; the page still renders and the restore still succeeds.
 *
 * Two layers, no second grammar:
 *   1. A conservative reject set applied to every value regardless of type —
 *      `{ } ; < >`, backslash escapes, control characters, the CSS comment
 *      delimiters `/ *` / `* /`, and `url(` / `expression(` / `@import` (CSS
 *      network-request, dynamic-eval, and import primitives). Since #579 this is
 *      the SHARED set (`_pp_forbidden_css_construct`, lib/apply.php) the write
 *      engine applies too, not a second, stricter copy of it. It is the sole line
 *      of defense for a (hypothetical) slot with no declared type.
 *   2. Where the slot type is known, delegation to the SAME shared engine used
 *      at write time (`_pp_validate_token_value`). Only its documented success
 *      shape (`=== true`) passes; any WP_Error drops the declaration.
 *
 * @param string      $value    The stored style value, cast to string.
 * @param string|null $type     The slot's declared type (color/length/gradient/…),
 *                              or null when no type context is available.
 * @param array|null  $allowed  For an `enum` slot, its bounded value set — passed
 *                              so the render boundary re-checks membership exactly
 *                              as the write boundary does (#330 parity: an
 *                              out-of-band-written value outside the set is dropped
 *                              here, not emitted). Null for non-enum types.
 * @return bool  True if the value is safe to emit; false to drop the declaration.
 */
function pp_render_style_value_allowed(string $value, ?string $type, ?array $allowed = null): bool {
    // Layer 1 — conservative reject set (defense-in-depth), every value.
    // ONE SET, TWO CALLERS (issue #579, A-33): this class used to be spelled out
    // here AND, differently, at the top of _pp_validate_token_value(). The gap
    // between the two spellings was a set of values that validated at write and
    // were dropped at render. Both callers now read _pp_forbidden_css_construct()
    // (lib/apply.php), so the write-accept set and the render-reject set cannot
    // drift apart again. Layer 1 stays a separate call rather than leaning on the
    // Layer 2 delegation below, because a (hypothetical) slot with no declared
    // type never reaches Layer 2 and this is its sole line of defense.
    if (_pp_forbidden_css_construct($value) !== null) {
        return false;
    }

    // Layer 2 — delegate to the shared write-time engine when type is known. For
    // an enum slot the value set is threaded through so membership is enforced at
    // the render boundary too, keeping every slot type equally re-validated (#330).
    if ($type !== null && _pp_validate_token_value($value, $type, $allowed) !== true) {
        return false;
    }

    return true;
}

/**
 * Whether ONE stored style declaration will actually be emitted (issue #575).
 *
 * The single predicate behind "does this paint?". The alias twin that originally
 * forced the extraction is gone (#603), but the reason it stays extracted is
 * stronger: pp_render_style_vars() (the renderer) and pp_validate_composition_smells()
 * (the guardrails advisories, lib/guardrails.php) must give the SAME answer, or the
 * advisories report on declarations the page never emits. Two hand-rolled copies of
 * that test would be two grammars, and the one that drifted would drop styling
 * silently or warn about nothing.
 *
 * Two gates, in order:
 *   1. DECLARED — the slot exists in the component's schema. An undeclared name is
 *      dropped with no finding (defensive; the action layer validates writes).
 *   2. ALLOWED — the stored value clears the #330 render boundary for the slot's
 *      declared type. Restore (#233) and out-of-band DB writes can both put a value
 *      into storage that never passed write-time validation.
 *
 * @param  string $name   Custom-property name exactly as stored (nothing resolves it).
 * @param  mixed  $value  The stored value.
 * @param  array  $slots  The component's declared style slots.
 * @return bool
 */
function pp_style_declaration_renders(string $name, $value, array $slots): bool {
    if (!isset($slots[$name])) {
        return false;
    }
    return pp_render_style_value_allowed(
        (string) $value,
        $slots[$name]['type'] ?? null,
        $slots[$name]['values'] ?? null
    );
}

/**
 * Renders style slot overrides as a CSS custom property string.
 *
 * Validates each property against the component's declared style slots.
 * Unknown properties and the __recipe tracking key are silently skipped.
 * Each value is re-validated at the render boundary via
 * pp_render_style_value_allowed() (issue #330); a rejected value is dropped
 * from the output while its sibling declarations still render.
 *
 * ITEM SCOPE (issue #579, A-19). Pass `$item_scope = true` when rendering the style
 * map of ONE element of an array prop (a grid card, a section panel row). The slot
 * set then narrows to the component's `item_eligible` subset, exactly as
 * _pp_validate_style_slot_map() narrows it at write time — through the SAME
 * predicate, pp_item_eligible_slots(), so the two cannot drift. Without this the
 * narrowing existed only at write, and a container-scoped slot arriving by a
 * non-validating path (raw meta write, restore_composition) was emitted on the
 * `<li>`. Opt-in by presence, mirroring the write path: a component whose slots
 * carry no item_eligible flag keeps the full set, so an un-annotated component that
 * gains a per-item style is not wholesale stripped by this shared renderer.
 *
 * Byte-identical for every validly-authored composition — a valid per-item style
 * contains only item-eligible slots by construction, because the write path already
 * rejected the rest.
 *
 * @param array  $style           Style overrides, e.g. ['--hero-bg' => '#1a1a2e'].
 * @param string $component_name  Component name, e.g. 'hero'.
 * @param bool   $item_scope      True when this map belongs to ONE item of an array
 *                                prop (grid card / section panel row); false (default)
 *                                for a component-level style map.
 * @return string  CSS custom property declarations, e.g. "--hero-bg: #1a1a2e; --hero-padding-top: 8rem"
 */
function pp_render_style_vars(array $style, string $component_name, bool $item_scope = false): string {
    if (empty($style)) {
        return '';
    }

    $slots      = pp_get_style_slots($component_name);
    if ($item_scope) {
        $eligible = pp_item_eligible_slots($slots);
        if ($eligible !== []) {
            $slots = $eligible;
        }
    }
    $properties = [];

    foreach ($style as $name => $value) {
        // Skip __recipe tracking key — not a CSS property.
        if ($name === '__recipe') {
            continue;
        }
        // Declared-slot filter + render boundary (#330). Since #603 this is the ONLY
        // gate: there is no legacy slot-NAME resolution above it any more. A slot name
        // this component does not declare is dropped here with a bare `continue`, and
        // that is the whole contract — a pre-#576 name stored on an old document is an
        // undeclared key like any other, and does not paint. The write path already
        // rejected such a name (_pp_validate_style_slot_map), so render and write now
        // give the same answer instead of two.
        if (!pp_style_declaration_renders($name, $value, $slots)) {
            continue;
        }
        $properties[] = esc_attr($name) . ': ' . esc_attr((string) $value);
    }

    return implode('; ', $properties);
}

/**
 * Maps a grid card's --grid-item-text-align value to the align-self keyword its
 * link/button must follow, returned as the internal plumbing custom property
 * --pp-grid-link-align (issue 361). This is the second mechanism the text-align
 * slot cannot supply on its own: .grid__item-link is a content-width flex item
 * placed by align-self, and per the issue 338 flex trap text-align never moves a
 * flex item's box — so after issue 357 a centered card centered its text but left
 * the "Read more" link pinned left. The operator still sets ONE authorable slot
 * (--grid-item-text-align); this derives the link's cross-axis placement from the
 * SAME value so the text and the link align together (no second schema slot).
 *
 * A keyword map is required, not a pass-through: align-self accepts start/end/
 * center but NOT left/right/justify, so a bare `align-self: var(--grid-item-text-
 * align)` would silently drop `right` (invalid value) and render it left. Physical
 * mapping (LTR theme, no rtl.css — mirrors the text-align slot's own physical
 * default in issue 357):
 *     left | start | justify -> flex-start   (also the CSS default)
 *     center                 -> center
 *     right | end            -> flex-end
 *
 * A companion is emitted for every RECOGNIZED value INCLUDING left -> flex-start,
 * so a per-card override resets a grid-level companion the card inherits from the
 * section by cascade proximity (a card set back to `left` must re-pin its link
 * left even when the grid centers the rest). An UNSET or unrecognized value emits
 * NOTHING, so the CSS fallback (flex-start) keeps every existing card byte-
 * identical to today. The value passes the SAME render boundary the text-align
 * slot itself passes (#330/#233): a stored value the shared engine would reject
 * (out-of-band write, restore) derives no companion, exactly as it renders no slot.
 *
 * @param array $style  A grid style-slot map (grid-level __pp_style or a card's style).
 * @return string  '--pp-grid-link-align: <keyword>', or '' when nothing should render.
 */
function pp_grid_link_align_decl(array $style): string {
    $value = $style['--grid-item-text-align'] ?? null;
    if (!is_string($value) || $value === '') {
        return '';
    }
    // Same render-boundary gate the text-align slot itself passes through, so a
    // value the shared engine rejects derives no companion (parity with #330).
    if (!pp_render_style_value_allowed($value, 'align')) {
        return '';
    }
    // The align type validates to exactly these six keywords (_pp_validate_align);
    // map them to their physical align-self equivalent. Anything else emits nothing.
    $map = [
        'left'    => 'flex-start',
        'start'   => 'flex-start',
        'justify' => 'flex-start',
        'center'  => 'center',
        'right'   => 'flex-end',
        'end'     => 'flex-end',
    ];
    $keyword = $map[$value] ?? null;
    if ($keyword === null) {
        return '';
    }
    return '--pp-grid-link-align: ' . $keyword;
}

/**
 * Renders an inline ` style="..."` attribute of CSS custom properties for
 * TEMPLATE-OWNED chrome — the header and footer, whose styling surface is
 * whitelisted site options (pp_header_* / pp_footer_*) rather than component
 * style_slots, so pp_render_style_vars() (which reads a component's schema
 * slots) does not apply to them.
 *
 * Each entry maps a CSS custom-property name to its value plus the SITE-OPTION
 * KEY that declares its type. The type is read from pp_allowed_site_options()
 * — the single source of truth — never hand-copied into the caller. That is the
 * point: the drift that silently dropped gradients before #333 was a render-time
 * type ('color') hardcoded separately from the whitelist's declared type
 * ('gradient'). Deriving the type here makes that divergence impossible.
 *
 * Each value is re-validated at the render boundary through the shared engine
 * (#330); a rejected or empty value is dropped while its siblings still render.
 * An all-empty/all-dropped set yields '' (no attribute at all), so unset chrome
 * is byte-identical to markup that never had the surface.
 *
 * @param array<string,array{value:string,option:string}> $vars
 *        CSS var name => ['value' => stored value, 'option' => whitelisted option key].
 * @return string  A ready-to-echo ` style="..."` attribute, or '' when nothing renders.
 */
function pp_chrome_style_attr(array $vars): string {
    $allowed = pp_allowed_site_options();
    $decls   = [];
    foreach ($vars as $css_var => $slot) {
        $value = (string) ($slot['value'] ?? '');
        if ($value === '') {
            continue;
        }
        // The CSS property name is developer-supplied (callers hardcode it), never
        // user input — but this is a shared primitive, so keep it structurally safe:
        // a custom property is `--` followed by name chars. Anything else is a caller
        // bug; drop it rather than emit an odd token into the attribute.
        if (!preg_match('/^--[A-Za-z0-9_-]+$/', (string) $css_var)) {
            continue;
        }
        // Type comes from the whitelist, keyed by the option name — never a
        // second, hand-maintained copy (the #333 drift class).
        $type = $allowed[$slot['option']] ?? null;
        // Fail CLOSED to an explicit CSS-color allowlist. This helper only ever emits
        // background/text/link COLOR surfaces, so only 'color' and 'gradient' may reach
        // the render boundary. An unresolved key (null) OR a resolved-but-non-style type
        // — e.g. a caller that names 'blogname' (string) or 'pp_footer_show_logo' (bool)
        // by mistake — is dropped here. Without this, pp_render_style_value_allowed()
        // would validate the value under a non-CSS type: _pp_validate_token_value() has
        // no case for 'string'/'bool'/'attachment_id', so it falls through to a permissive
        // pass, leaving only the layer-1 injection reject set. Constraining the type is
        // strictly safer and keeps the drift-proofing above meaningful.
        if ($type !== 'color' && $type !== 'gradient') {
            continue;
        }
        if (!pp_render_style_value_allowed($value, $type)) {
            continue;
        }
        $decls[] = $css_var . ': ' . $value;
    }
    // esc_attr on the whole attribute value is defense-in-depth on output; the
    // render boundary above is the real gate.
    return $decls ? ' style="' . esc_attr(implode('; ', $decls)) . '"' : '';
}

/**
 * Renders a heading title with an optional accent-colored substring (#110).
 *
 * A structured, plain-text mechanism — NOT an HTML/markup allowlist. `$title`
 * and `$accent` are both ordinary escaped text; this only decides WHERE to
 * split them and wraps the matched segment in a `<span>`. There is no new
 * markup-parsing or sanitization surface: every fragment goes through
 * esc_html() exactly as a plain title always did.
 *
 * `$accent` must be a real, non-empty substring of `$title` (case-sensitive,
 * first occurrence). If it isn't — unset, empty, or not actually found in
 * the title — the accent is silently ignored and the title renders exactly
 * as it always has, matching this codebase's established "invalid override
 * falls back to the safe default" pattern for props.
 *
 * @param string $title        Full heading text.
 * @param string $accent       Substring of $title to wrap in an accent span. Empty/no-match = no accent.
 * @param string $accent_class CSS class for the accent <span>, e.g. "hero__title-accent".
 * @return string  Safe-to-echo HTML (already escaped) — do not pass through esc_html() again.
 */
function pp_render_heading_with_accent(string $title, string $accent, string $accent_class): string {
    if ($accent === '') {
        return esc_html($title);
    }
    $pos = strpos($title, $accent);
    if ($pos === false) {
        return esc_html($title);
    }
    $before = substr($title, 0, $pos);
    $after  = substr($title, $pos + strlen($accent));
    return esc_html($before)
        . '<span class="' . esc_attr($accent_class) . '">' . esc_html($accent) . '</span>'
        . esc_html($after);
}

/**
 * Renders a FAQPage JSON-LD <script> tag from FAQ items (#3), or '' if
 * there are no complete (question + answer) items to describe. Always-on,
 * zero-config — the FAQ component already has everything the schema needs.
 *
 * Google's FAQPage schema expects plain-text answers, not HTML — question
 * and answer are both passed through wp_strip_all_tags() before encoding.
 * This is also the primary defense against a </script> breakout: WordPress's
 * wp_strip_all_tags() strips <script>/<style> tags AND their content via a
 * regex pass before the general strip_tags() call, so no well-formed tag
 * markup survives into the JSON payload at all. wp_json_encode()'s default
 * forward-slash escaping (this does NOT pass JSON_UNESCAPED_SLASHES) is a
 * second, redundant layer: even if a "</script>"-shaped substring somehow
 * reached this point some other way, it would encode as "<\/script>",
 * which cannot close the surrounding <script> tag.
 *
 * ── #742: the element-level guards, and the coherence contract they land ─────
 *
 * This helper re-reads each element's `question` and `answer` ITSELF, with its
 * own `(string)` cast and its own array-offset read. Those are LANGUAGE
 * constructs, not a typed call and not a core escaper, so they sit outside
 * every guard the calling component applies: #739 guards faq's `items`
 * CONTAINER and #730 guards each item's `answer` before it reaches
 * wp_kses_post(), and neither reaches inside this function. Measured, one
 * render per shape. READ THE RIGHT-HAND COLUMN: only three of these five were
 * reachable through the composition render loop, and conflating that would
 * claim a page-level fix this change does not make.
 *
 *   answer   = object   FATAL  Object of class X could not be converted   [render loop]
 *   answer   = array    emitted "text":"Array" into the FAQPage payload   [render loop]
 *   question = array    emitted "name":"Array"  into the FAQPage payload  [render loop]
 *   question = object   FATAL  (same cast)                               [helper only *]
 *   item     = object   FATAL  Cannot use object of type X as array      [helper only *]
 *
 *   * through the render loop these two fatal UPSTREAM, inside faq.php's own
 *     visible loop, before this function is reached at all — see WHAT THIS
 *     DOES NOT CLOSE below. They are measured here by calling the helper
 *     directly, the only way to observe this boundary's own behaviour.
 *
 * templates/composition.php calls pp_get_component() with no try/catch, so each
 * object row was a whole-page 500; the array rows were worse in a quieter way,
 * because they published the literal word `Array` as answer text in the page's
 * machine-readable SEO payload, where no human looks.
 *
 * THE GUARDS. `is_array($item)` because an array IS the contract at an offset
 * read of a decoded composition element, and `is_scalar($raw) ? (string) $raw`
 * because a STRING is the contract at the cast: PHP runs coercive here (no
 * declare(strict_types) anywhere in this theme), so only non-scalars ever
 * fataled, and is_string() would silently DROP stored scalars the write path
 * accepts (#707 closed the front door on new ones; it migrated none of the
 * existing ones). Both are the ratified #641/#705 idiom in its two stated
 * forms. A Stringable object degrades too, deliberately: is_scalar() is false
 * for every object, and nothing at this layer can vouch for what a stored
 * object's __toString() would return. Same for ArrayAccess at the element
 * guard — an offset read that runs arbitrary code is not the decoded-JSON
 * shape this contract describes.
 *
 * THE COHERENCE CONTRACT, which is the point of the fix rather than a side
 * effect: a damaged question or answer degrades to '' and is therefore skipped
 * by the PRE-EXISTING empty-value `continue` below — the same treatment a
 * stored-empty value has always had. No new rule was invented for the VALUES;
 * the element guard is the one skip this change adds, and it catches only the
 * shape that used to fatal at the offset read instead of reaching any rule.
 * So the JSON-LD never publishes text the accordion suppressed as corrupt, and
 * when every item degrades out `$entities` is empty and NO fragment is emitted
 * at all, matching the empty state #739 landed for a malformed `items`.
 *
 * WHAT THIS DOES NOT CLOSE, named so the fix is not read as broader than it is.
 * Both are pre-existing, both are the visible loop's rather than this helper's,
 * and both are measured facts pinned in tests/StoredLinkAndRichTextRenderGuardTest.php:
 *   - components/faq/faq.php reads `question` UNGUARDED into esc_html(), so an
 *     OBJECT question and an OBJECT element still 500 the page upstream of this
 *     call, and an ARRAY question still paints the literal `Array` in the
 *     accordion summary. That is the #736 class (esc_html coercion), not this
 *     one; guarding it here would not help, because the fatal happens first.
 *   - a `0` / `'0'` question renders NO accordion item (the visible loop's
 *     `if (!$question)` is a truthiness gate) while this helper's `=== ''`
 *     comparison keeps it. Pre-existing, unrelated to stored-value damage, and
 *     a separate ruling on which of the two gates is correct.
 *
 * @param array $items  FAQ items: [{question, answer}, ...].
 * @return string  A full <script type="application/ld+json">...</script> tag, or ''.
 */
function pp_render_faq_schema(array $items): string {
    $entities = [];
    foreach ($items as $item) {
        // #742: an OBJECT element fatals at the offset read below, before any
        // cast. A scalar element is already harmless (`??` suppresses the read
        // and both locals end up ''), so this skips exactly what it must and
        // leaves every other shape landing where it always did.
        if (!is_array($item)) {
            continue;
        }
        $raw_question = $item['question'] ?? '';
        $raw_answer   = $item['answer']   ?? '';
        $question = is_scalar($raw_question) ? wp_strip_all_tags((string) $raw_question) : '';
        $answer   = is_scalar($raw_answer)   ? wp_strip_all_tags((string) $raw_answer)   : '';
        if ($question === '' || $answer === '') {
            continue;
        }
        $entities[] = [
            '@type' => 'Question',
            'name'  => $question,
            'acceptedAnswer' => [
                '@type' => 'Answer',
                'text'  => $answer,
            ],
        ];
    }

    if (empty($entities)) {
        return '';
    }

    $schema = [
        '@context'   => 'https://schema.org',
        '@type'      => 'FAQPage',
        'mainEntity' => $entities,
    ];

    return '<script type="application/ld+json">' . wp_json_encode($schema) . '</script>' . "\n";
}

/**
 * Safely escapes an image source for output in <img src="..."> or a CSS
 * background-image:url(...) value embedded in an HTML style attribute.
 *
 * esc_url() rejects the 'data' URI scheme entirely (not in WordPress core's
 * default protocol whitelist), silently reducing any data:image/... value to
 * an empty string — the exact production bug this closes (#36): a
 * data:image/svg+xml,... hero image rendered with src="". Even if 'data'
 * were whitelisted, esc_url()'s general-purpose character-stripping isn't
 * safe for a raw (non-base64) SVG payload's XML markup (quotes, angle
 * brackets) — it would mangle the image or, worse, leave characters that
 * break out of the surrounding HTML attribute / unquoted CSS url() token.
 *
 * Non-data URLs are unaffected — this delegates straight to esc_url(),
 * identical to every call site's prior behavior.
 *
 * For data: URIs, this only accepts image/{png,jpeg,jpg,gif,webp,svg+xml} —
 * never data:text/html or other schemes/types.
 *
 * SECURITY NOTE (do not weaken without re-reading this): a browser does NOT
 * treat SVGs rendered as an image (via <img src> or CSS background-image) as
 * script-executing — but every <img> call site here gives an ordinary user a
 * standard browser feature ("Open image in new tab", copy-image-address,
 * drag-to-new-tab) that navigates to the data: URI as a TOP-LEVEL document,
 * where SVG script execution IS enabled. So the SVG content validation
 * below is the PRIMARY defense against stored XSS via an AI-chat-and-human-editable
 * image_url/background_image prop, not defense in depth on top of the
 * browser (adversarial review finding — an earlier version of this
 * docblock got that backwards). Getting the check-then-encode ordering
 * exactly right matters:
 *  - The safety check always runs against the FULLY, repeatedly
 *    percent-decoded payload (not the raw input) — otherwise an
 *    already-percent-encoded payload (single- or multiply-encoded) sails
 *    past a literal-substring blocklist and only becomes dangerous once the
 *    browser decodes it (cross-model review finding).
 *  - The final re-encoding step only ever REPLACES a character with its
 *    percent-encoding — it never DELETES one. Deleting a character can
 *    silently merge two adjacent, previously-separated fragments back into
 *    a blocked keyword (e.g. "&lt;scr" + [deleted newline] + "ipt&gt;"
 *    becomes "&lt;script&gt;") — the exact bypass a cross-model review
 *    caught in an earlier version of this function.
 *  - SVG content is validated by actually parsing it as XML (DOMDocument +
 *    DOMXPath) and checking resolved element/attribute local names — a
 *    literal-substring blocklist is defeated by XML namespace-prefix
 *    renaming (e.g. "<x:script>" with an xmlns:x bound to the SVG
 *    namespace) and by character references reconstructing a blocked
 *    string at parse time; a real parser resolves both correctly by
 *    construction. See _pp_svg_content_is_safe().
 *  - Base64 payloads are decoded and parsed with the exact same check —
 *    "the alphabet is inert" only protects the HTML/CSS transport, not the
 *    SVG document it decodes to.
 *
 * @param string $url    Candidate image source (http(s) URL, relative path, or data: URI).
 * @param int    $depth  Internal recursion guard — a nested data: URI found
 *                        inside an SVG's own href/xlink:href is validated by
 *                        calling this function again (see
 *                        _pp_svg_content_is_safe()); this bounds how deeply
 *                        data URIs can nest inside each other before being
 *                        rejected outright. Callers never need to pass this.
 * @return string  Safe-to-echo value, or '' if the input is empty or rejected.
 */
function pp_esc_image_src(string $url, int $depth = 0): string {
    if ($url === '') {
        return '';
    }

    if ($depth > 3) {
        return '';
    }

    if (stripos($url, 'data:') !== 0) {
        // esc_url()'s allowed-character set includes '(' and ')' (it's
        // designed for generic href="" safety, not this CSS-unquoted-url()
        // context specifically) — an ordinary URL containing a literal ')'
        // can still close the surrounding CSS url() token early and inject
        // trailing declarations into the style attribute (adversarial
        // review finding; pre-existing behavior, not introduced by this
        // function, but this is now the one place all 6 call sites route
        // through). Percent-encoding it is transparent to every legitimate
        // consumer of the URL.
        return str_replace(')', '%29', esc_url($url));
    }

    // Sanity bound against pathologically large inline payloads.
    $max_data_uri_length = 1_000_000;
    if (strlen($url) > $max_data_uri_length) {
        return '';
    }

    if (!preg_match('/^data:image\/(png|jpe?g|gif|webp|svg\+xml)(;charset=([a-z0-9_-]+))?(;base64)?,(.*)$/isD', $url, $m)) {
        return '';
    }

    $mime      = strtolower($m[1]);
    $charset   = $m[3];
    $is_base64 = $m[4] !== '';
    $raw_data  = $m[5];

    // Only utf-8 (or unspecified, which defaults to utf-8 for text-ish
    // media types) is allowed. Other charsets — utf-7 above all — are a
    // known vector for a downstream parser to reconstruct blocked byte
    // sequences that a byte-level blocklist never sees (cross-model finding).
    if ($charset !== '' && strtolower($charset) !== 'utf-8') {
        return '';
    }
    $charset_segment = $charset !== '' ? ';charset=utf-8' : '';

    if ($is_base64) {
        // 'D' anchors $ to the true string end (without it, PCRE also
        // matches just before a single trailing "\n", which would let an
        // unescaped newline slip into an unquoted CSS url() token below).
        if ($raw_data === '' || !preg_match('/^[A-Za-z0-9+\/]+={0,2}$/D', $raw_data)) {
            return '';
        }
        if ($mime === 'svg+xml') {
            $decoded = base64_decode($raw_data, true);
            if ($decoded === false || !_pp_svg_content_is_safe($decoded, $depth)) {
                return '';
            }
        }
        // Base64 alphabet is inert in both HTML-attribute and CSS-url()
        // contexts — the string is returned exactly as validated.
        return 'data:image/' . $mime . $charset_segment . ';base64,' . $raw_data;
    }

    // Raw/percent-encoded payload. Decode to a TRUE fixed point (handles
    // single- AND arbitrarily-multiply-percent-encoded input identically)
    // so the safety check runs against the same logical content the
    // browser will eventually parse. A capped-but-incomplete decode is not
    // safe here: this function's own re-encoding step below only adds new
    // %XX sequences for specific dangerous characters, it never touches a
    // pre-existing '%' — so any encoding layer left un-decoded by the
    // check survives into the output and gets removed by the browser's own
    // single decode pass at render time, revealing content the check never
    // saw (adversarial review finding: a 6-times-encoded payload survived
    // an earlier 5-round cap this way). If genuine convergence takes more
    // than $max_decode_rounds passes, that is itself not legitimate
    // content — reject outright rather than proceed on a partial decode.
    $max_decode_rounds = 20;
    $decoded = $raw_data;
    $converged = false;
    for ($i = 0; $i < $max_decode_rounds; $i++) {
        $next = rawurldecode($decoded);
        if ($next === $decoded) {
            $converged = true;
            break;
        }
        $decoded = $next;
    }
    if (!$converged) {
        return '';
    }

    if ($mime === 'svg+xml' && !_pp_svg_content_is_safe($decoded, $depth)) {
        return '';
    }

    // Percent-ENCODE (never strip) every character that's unsafe once
    // embedded in an HTML attribute that also contains an unquoted CSS
    // url() token: quotes, angle brackets, parens, #, &, backslash, DEL
    // (\x7F), and all C0 control/whitespace characters (\x00-\x20, which
    // covers space, tab, newline, and CR in one pass). Backslash matters
    // even though it looks inert: CSS's own url()-token tokenizer resolves
    // "\XX" backslash escapes (independent of, and prior to,
    // percent-decoding) while extracting the token's value — content that
    // is completely inert to DOMDocument (e.g. a literal "\3c" is just
    // three harmless characters to an XML parser) can decode to "<" once
    // the browser's CSS tokenizer processes the surrounding
    // style="...url(...)..." attribute, at the 4 background-image call
    // sites (adversarial review finding). DEL is also non-printable per
    // the CSS Syntax spec's unquoted-url-token grammar (adversarial review
    // finding, low severity — worst case is a broken url() token, not a
    // context breakout, since every character a "bad url" recovery scan
    // could otherwise exploit is already excluded here).
    $encoded = preg_replace_callback(
        '/["\'<>()#&\\\\\x00-\x20\x7F]/',
        function (array $match): string {
            return '%' . strtoupper(bin2hex($match[0]));
        },
        $decoded
    );

    return 'data:image/' . $mime . $charset_segment . ',' . $encoded;
}

/**
 * Renders a component's <img> tag, responsively when possible.
 *
 * When $attachment_id resolves to a real, existing attachment,
 * renders via wp_get_attachment_image() so WordPress emits srcset/sizes
 * from its registered image sizes (#107). Falls back to a plain
 * <img src> using $url when no attachment id is set, or it doesn't
 * resolve to anything (deleted attachment, wrong id) — every composition
 * that only ever set a raw image_url (hotlinked or otherwise) keeps
 * rendering exactly as before this function existed.
 *
 * @param string $url            Fallback image URL.
 * @param string $alt            Alt text.
 * @param string $class          CSS class for the <img> tag.
 * @param string $loading        'lazy' or 'eager'.
 * @param int    $attachment_id  Optional Media Library attachment ID.
 * @return string  Full <img> HTML, already escaped.
 */
function pp_render_responsive_image(string $url, string $alt, string $class, string $loading, int $attachment_id = 0): string {
    if ($attachment_id > 0) {
        $html = wp_get_attachment_image($attachment_id, 'large', false, [
            'class'   => $class,
            'alt'     => $alt,
            'loading' => $loading,
        ]);
        if ($html !== '') {
            return $html;
        }
    }

    if ($url === '') {
        return '';
    }

    return sprintf(
        '<img src="%s" alt="%s" class="%s" loading="%s">',
        pp_esc_image_src($url),
        esc_attr($alt),
        esc_attr($class),
        esc_attr($loading)
    );
}

/**
 * True if decoded SVG markup contains no script-executing constructs.
 * See the security note on pp_esc_image_src() — this is the primary
 * defense, not defense in depth, because a data: URI is reachable as a
 * top-level navigation (e.g. "Open image in new tab") regardless of how
 * it's embedded in the page.
 *
 * Parses with DOMDocument/DOMXPath rather than string/regex matching. A
 * regex blocklist on raw text is defeated by XML namespace prefix renaming
 * — e.g. `<x:script>` with `xmlns:x="http://www.w3.org/2000/svg"` declared
 * elsewhere in the document is exactly equivalent to `<script>` per XML
 * namespace rules, but a literal "<script" substring search never sees it
 * (cross-model review finding). XPath's local-name() resolves the TRUE
 * element/attribute name regardless of prefix, closing that class of
 * bypass by construction instead of by an ever-growing list of special
 * cases. A real parser also resolves standard XML character references
 * (e.g. "&#x61;" -> "a") as a normal part of parsing, so by the time an
 * attribute value is inspected here it's already the same string a
 * rendering engine would see — no separate entity-obfuscation check needed.
 *
 * DOCTYPE is rejected outright (not "parsed carefully") — this closes the
 * entire external-entity/DTD attack surface without depending on getting a
 * specific combination of libxml flags exactly right.
 */
/**
 * Resolves CSS Syntax Level 3 escape sequences (a backslash followed by
 * 1-6 hex digits and an optional trailing whitespace terminator names a
 * codepoint; a backslash followed by any other character is that character
 * literally) anywhere in a string, including inside what would otherwise
 * read as a bare identifier or function name.
 *
 * This matters because a browser's CSS value parser resolves these escapes
 * BEFORE it recognizes tokens such as the "url" function name — an SVG
 * presentation attribute value like `fill="u\72l(https://evil.test/p.svg)"`
 * is not the literal substring "url(" and evades a plain substring/regex
 * scan for it, but Chromium resolves `\72` to "r" while parsing the value
 * and fetches the external resource exactly as if "url(" had been written
 * literally (sixth-round adversarial review finding, confirmed empirically
 * via Playwright in both raw and base64-encoded payload forms). Scanning
 * the CSS-unescaped form of every attribute value closes this by resolving
 * escapes the same way a real CSS parser would, rather than special-casing
 * "url" — the same escape trick could equally hide "javascript:" or
 * "data:" from the scheme checks below, so both scans run on the
 * unescaped form too.
 */
function _pp_css_unescape(string $value): string {
    $decoded = preg_replace_callback(
        '/\\\\(?:([0-9a-fA-F]{1,6})[ \t\n\r\f]?|(.))/su',
        function (array $m): string {
            if (($m[1] ?? '') !== '') {
                $code = hexdec($m[1]);
                if ($code === 0 || $code > 0x10FFFF || ($code >= 0xD800 && $code <= 0xDFFF)) {
                    return "\u{FFFD}";
                }
                return mb_chr($code, 'UTF-8');
            }
            return $m[2] ?? '';
        },
        $value
    );

    return $decoded ?? $value;
}

function _pp_svg_content_is_safe(string $svg, int $depth = 0): bool {
    if (stripos($svg, '<!doctype') !== false) {
        return false;
    }

    $prev_setting = libxml_use_internal_errors(true);
    $doc = new \DOMDocument();
    // No LIBXML_DTDLOAD/LIBXML_DTDATTR — never fetch or apply an external
    // DTD (moot anyway since DOCTYPE is rejected above, but belt and
    // suspenders). LIBXML_NONET blocks any network access during parsing.
    $parsed = $doc->loadXML($svg, LIBXML_NONET);
    libxml_clear_errors();
    libxml_use_internal_errors($prev_setting);

    if (!$parsed) {
        return false;
    }

    $xpath = new \DOMXPath($doc);
    $lower_local_name = "translate(local-name(), 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz')";

    // Any processing instruction (e.g. <?xml-stylesheet href="javascript:...">
    // before the root element) is rejected outright rather than inspected —
    // PIs aren't attributes, so the attribute-value checks below never see
    // one, and xml-stylesheet's href has real (if legacy/browser-specific)
    // script/resource-loading-triggering history (adversarial review finding).
    $pis = $xpath->query('//processing-instruction()');
    if ($pis === false || $pis->length > 0) {
        return false;
    }

    // SMIL animation elements (animate/animateMotion/animateTransform/
    // animateColor/set) can retarget attributes like href/xlink:href to a
    // javascript: value over time via their values/to/from attributes —
    // a static per-attribute scan doesn't parse that semicolon-separated
    // mini-language, so these elements are rejected outright. <style> is
    // also rejected outright: unlike <script>, an SVG's own <style> element
    // IS applied during ordinary rendering (as an <img> or CSS
    // background-image, not just a top-level-navigation "open image in new
    // tab") — an `@import`/`url()` inside it fires on every page view, not
    // just for a user who takes an extra action (adversarial review finding).
    $dangerous_elements = ['script', 'foreignobject', 'iframe', 'embed', 'object', 'animate', 'animatemotion', 'animatetransform', 'animatecolor', 'set', 'style'];
    foreach ($dangerous_elements as $name) {
        $matches = $xpath->query("//*[{$lower_local_name}='{$name}']");
        if ($matches === false || $matches->length > 0) {
            return false;
        }
    }

    // Event-handler attributes (onload, onclick, ...), any element/namespace.
    $on_attrs = $xpath->query("//@*[starts-with({$lower_local_name}, 'on')]");
    if ($on_attrs === false || $on_attrs->length > 0) {
        return false;
    }

    // xml:base (SVG/XML's equivalent of HTML's <base href>) is rejected
    // outright. Every check below treats a "#fragment" reference (and,
    // symmetrically, a value starting with "data:") as unconditionally safe
    // on the theory that a fragment-only reference always resolves within
    // the current document — but per RFC 3986 §5.3, resolving a
    // fragment-only reference against a base URL yields the BASE's
    // scheme+authority+path with only the fragment replaced. xml:base on
    // the root <svg> (or any ancestor) sets exactly that base, so
    // `fill="url(#leak)"` or `<use href="#leak">` would resolve against an
    // attacker-controlled origin instead of the document itself, turning
    // an allowed-by-design same-document reference into a cross-origin
    // fetch during ordinary rendering — undermining the "#... is always
    // safe" assumption both the url() scan and the href scan below rely on
    // (fifth-round Claude adversarial review finding). No legitimate
    // AI/human-authored image SVG has a reason to override its own base
    // URI, so the whole attribute is rejected rather than special-cased.
    $xml_base_attrs = $xpath->query("//@*[namespace-uri()='http://www.w3.org/XML/1998/namespace' and local-name()='base']");
    if ($xml_base_attrs === false || $xml_base_attrs->length > 0) {
        return false;
    }

    $all_attrs = $xpath->query('//@*');
    if ($all_attrs === false) {
        return false;
    }
    foreach ($all_attrs as $attr) {
        $value = (string) $attr->nodeValue;
        // Resolve CSS escape sequences before pattern-matching — see
        // _pp_css_unescape() docblock. A value with no escapes is unchanged.
        $unescaped = _pp_css_unescape($value);

        // SVG has many presentation attributes/CSS properties that reference
        // another resource via a CSS-style url(...) wrapper — fill, stroke,
        // filter, mask, clip-path, marker-start/mid/end, and cursor (usually
        // via a style="..." attribute) among them. All of these are applied
        // during ORDINARY rendering (fetched for every visitor, not gated
        // behind a click or top-level navigation) — confirmed empirically
        // against a real browser (adversarial review: style="filter:url(...)",
        // filter="url(...)", fill="url(...)", and style="cursor:url(...)"
        // all triggered real network requests to an external host when
        // rendered). Rather than enumerate every attribute name that can
        // carry a url() reference (an open-ended, easy-to-miss list), every
        // attribute value is scanned generically for the url(...) pattern
        // and any reference that isn't a same-document fragment or a
        // data: URI is rejected.
        if (preg_match_all('/url\(\s*[\'"]?([^\'")]*)[\'"]?\s*\)/i', $unescaped, $url_matches)) {
            foreach ($url_matches[1] as $ref) {
                $ref = trim($ref);
                if ($ref !== '' && $ref[0] !== '#' && stripos($ref, 'data:') !== 0) {
                    return false;
                }
            }
        }

        // Dangerous URI schemes in any attribute value (href, xlink:href,
        // etc.) — already-entity-resolved by the parser, so no separate
        // decoding needed. Tab/newline/CR are stripped before the prefix
        // check because browser URL parsing strips them from anywhere in a
        // URL string (a well-known normalization step, not unique to this
        // codebase) — "java&#x0A;script:" resolves to "javascript:" at
        // navigation time even though the DOM attribute value still has the
        // embedded newline (adversarial review finding). data:text/html (or
        // any non-image data: scheme) is rejected alongside javascript: — a
        // nested <a href> inside an already-opened SVG is a real, if
        // lower-likelihood, escalation path.
        $normalized = preg_replace('/[\t\n\r]/', '', $unescaped);
        if (preg_match('/^\s*javascript:/i', $normalized)) {
            return false;
        }
        if (preg_match('/^\s*data:(?!image\/)/i', $normalized)) {
            return false;
        }
    }

    // href/xlink:href are fetched during ordinary rendering on many more
    // elements than just <use>/<image> — <feImage> (an SVG filter
    // primitive whose entire purpose is fetching an image resource) has
    // the exact same semantics and is neither a "dangerous element" nor
    // named use/image; <pattern>, gradients, and <textPath> can carry the
    // same reference. Rather than enumerate elements (an open-ended,
    // easy-to-miss list — the same mistake already made once in this
    // function for <use>/<image> specifically), every href/xlink:href
    // attribute in the document is checked regardless of which element
    // it's on (adversarial review finding). local-name()='href' matches
    // both the modern unprefixed `href` and the legacy `xlink:href` — an
    // XML namespace prefix doesn't change the local name, only the
    // namespace it resolves to. Restrict to a same-document fragment (#id)
    // or a data: URI; reject any external reference.
    $href_attrs = $xpath->query("//@*[{$lower_local_name}='href']");
    if ($href_attrs === false) {
        return false;
    }
    foreach ($href_attrs as $href) {
        $value = trim((string) $href->nodeValue);
        if ($value === '' || $value[0] === '#') {
            continue;
        }
        if (stripos($value, 'data:') !== 0) {
            return false;
        }
        // A nested data: URI (e.g. a <use href="data:image/svg+xml,...">)
        // is not automatically safe just because it's a data: URI — it
        // must pass the exact same validation as the outer one, recursively.
        // Whether <use>'s clone-based reference model treats this content
        // as inert "image data" or a live, scriptable subtree wasn't
        // something static analysis could rule out with confidence
        // (adversarial review finding) — recursing removes the ambiguity
        // outright rather than shipping an unconfirmed-but-plausible gap in
        // what this function's own docblock calls the primary XSS defense.
        if (pp_esc_image_src($value, $depth + 1) === '') {
            return false;
        }
    }

    return true;
}

/**
 * Returns all design token overrides from the database.
 * These are site-specific values that override the product defaults in base.css.
 *
 * @return array  Associative array of token name => value, e.g. ['--color-accent' => '#b45309'].
 */
function pp_get_token_overrides(): array {
    $overrides = get_option('pp_token_overrides', []);
    if (!is_array($overrides)) {
        return [];
    }
    return $overrides;
}

// ── Token-override write serialization (#97) ────────────────────────────────
// All three writers below do a read-modify-write on the single `pp_token_overrides`
// option. Concurrent applies (agents are told to parallelize tool calls) otherwise
// last-writer-wins and silently lose updates. We serialize the critical section with
// a connection-scoped MySQL advisory lock (GET_LOCK).

/**
 * Bounded GET_LOCK wait (seconds) so a stuck holder cannot block an apply forever.
 * Override with the PP_TOKEN_LOCK_TIMEOUT constant.
 */
function _pp_token_lock_timeout(): int {
    return defined('PP_TOKEN_LOCK_TIMEOUT') ? (int) PP_TOKEN_LOCK_TIMEOUT : 5;
}

/**
 * Install-scoped advisory lock name for the pp_token_overrides option. Includes DB
 * name + blog id so writers on the SAME store serialize while unrelated sites/installs
 * never collide. MySQL caps lock names at 64 chars; the md5 slice keeps it bounded.
 */
function _pp_token_lock_name(): string {
    global $wpdb;
    $db   = defined('DB_NAME') ? DB_NAME : (isset($wpdb->dbname) ? $wpdb->dbname : 'db');
    $blog = function_exists('get_current_blog_id') ? (int) get_current_blog_id() : 0;
    return 'pp_tokovr_' . substr(md5($db . '|' . $blog), 0, 32);
}

/**
 * Runs $mutator inside a serialized critical section guarded by a named MySQL advisory
 * lock. Shared engine behind both the token-override lock (_pp_with_token_lock, one
 * install-scoped name) and the per-post composition lock (_pp_with_composition_lock,
 * one name per post) — they differ only in the lock NAME, so the GET_LOCK acquire /
 * bounded-timeout / release-in-finally / degrade-without-$wpdb machinery lives here once.
 *
 * Acquires the lock with a bounded timeout, runs the mutator, and releases in `finally`
 * so normal AND exception unwinding both free it. This is not an absolute guarantee — a
 * hard fatal/SIGKILL or a persistent connection can still strand a lock — so the bounded
 * acquire timeout and MySQL's connection-close auto-release are the backstops. On
 * acquisition failure the mutator does NOT run and $fail_value is returned (explicit
 * failure, never a silent partial write). Degrades to running the mutator directly when
 * no $wpdb is present (unit context); production always has $wpdb.
 *
 * @param string   $lock_name   The raw advisory-lock name (bounded <= 64 chars by callers).
 * @param callable $mutator     Performs the cache-authoritative read/modify/write.
 * @param mixed    $fail_value  Returned if the lock cannot be acquired.
 * @param string   $context     Short label for the error_log line on acquisition failure.
 * @return mixed
 */
function _pp_with_advisory_lock(string $lock_name, callable $mutator, $fail_value, string $context = 'advisory lock') {
    global $wpdb;
    $has_db = isset($wpdb) && is_object($wpdb) && method_exists($wpdb, 'get_var');
    if (!$has_db) {
        return $mutator(null);
    }

    $name = $wpdb->prepare('%s', $lock_name);
    // GET_LOCK: '1' acquired, '0' timed out (another writer holds it), NULL on error
    // (killed connection, OOM, privilege) or on a backend without GET_LOCK. WordPress
    // core requires MySQL/MariaDB, which always support GET_LOCK, so any non-'1' result
    // means we could not safely serialize. Skip the write and surface an explicit
    // failure for the caller to retry, rather than write unlocked and risk a lost
    // update — NULL most often means the DB is unhealthy, exactly when the race bites.
    $got = $wpdb->get_var("SELECT GET_LOCK($name, " . _pp_token_lock_timeout() . ")");
    if ($got !== '1' && $got !== 1) {
        $reason = ($got === '0' || $got === 0)
            ? 'lock busy (GET_LOCK timed out after ' . _pp_token_lock_timeout() . 's)'
            : 'lock unavailable (GET_LOCK returned ' . var_export($got, true) . ')';
        error_log('PromptingPress: ' . $context . ' ' . $reason
            . '; write skipped to avoid a lost update.');
        return $fail_value;
    }

    try {
        return $mutator($wpdb);
    } finally {
        $wpdb->query("SELECT RELEASE_LOCK($name)");
    }
}

/**
 * Runs $mutator inside a serialized critical section for pp_token_overrides.
 *
 * Thin wrapper over _pp_with_advisory_lock() keyed on the single install-scoped token
 * lock name. On acquisition failure the mutator does NOT run and $fail_value is returned.
 *
 * @param callable $mutator     Performs the cache-authoritative read/modify/write.
 * @param mixed    $fail_value  Returned if the lock cannot be acquired.
 * @return mixed
 */
function _pp_with_token_lock(callable $mutator, $fail_value) {
    return _pp_with_advisory_lock(_pp_token_lock_name(), $mutator, $fail_value, 'pp_token_overrides');
}

// ── Composition-write serialization (#113) ──────────────────────────────────
// pp_update_composition() does a read-modify-write of the freshness marker
// (_pp_composition_version / _pp_composition_hash) alongside the composition
// itself. Concurrent writers to the SAME post otherwise interleave and lose a
// version bump. We serialize per post with a MySQL advisory lock — the #200
// lesson (a lock-acquire failure propagates, never a silent non-atomic write),
// applied at composition-write time.

/**
 * Per-post advisory lock name for a composition write. Includes DB name + blog id (so
 * writers on the SAME store serialize while unrelated sites/installs never collide) plus
 * the post id (so writes to DIFFERENT posts never serialize against each other). MySQL
 * caps lock names at 64 chars; the md5 slice keeps it bounded regardless of DB name.
 */
function _pp_composition_lock_name(int $post_id): string {
    global $wpdb;
    $db   = defined('DB_NAME') ? DB_NAME : (isset($wpdb->dbname) ? $wpdb->dbname : 'db');
    $blog = function_exists('get_current_blog_id') ? (int) get_current_blog_id() : 0;
    return 'pp_comp_' . substr(md5($db . '|' . $blog . '|' . $post_id), 0, 32);
}

/**
 * Runs $mutator inside a serialized critical section for a single post's composition.
 * Thin wrapper over _pp_with_advisory_lock() keyed per post. On acquisition failure the
 * mutator does NOT run and $fail_value is returned.
 *
 * @param int      $post_id     The post whose composition write is being serialized.
 * @param callable $mutator     Performs the marker read/bump + composition write.
 * @param mixed    $fail_value  Returned if the lock cannot be acquired.
 * @return mixed
 */
function _pp_with_composition_lock(int $post_id, callable $mutator, $fail_value) {
    return _pp_with_advisory_lock(
        _pp_composition_lock_name($post_id),
        $mutator,
        $fail_value,
        'composition post ' . $post_id
    );
}

/**
 * Reads the current _pp_composition_version straight from the DB inside the composition
 * lock, bypassing the post-meta object cache (#113).
 *
 * The freshness check (pp_get_composition_marker) runs BEFORE the lock and warms the meta
 * cache with the pre-write version. Reading through get_post_meta() inside the lock would
 * then return that stale cached value, so two writers serialized by the lock could both
 * compute the same next version — a lost bump, the exact lost-update the lock exists to
 * prevent (the #200 lesson, mirrored from _pp_read_token_overrides_locked_strict). Reading
 * the one meta row directly keeps the counter monotonic per write. Degrades to the cached
 * read when no $wpdb is present (unit context), where the store isn't shared across
 * processes so staleness can't arise.
 *
 * ORDER BY meta_id ASC, added by #825 to match the sibling readers that already carried it
 * (pp_get_composition_result_authoritative, #767; _pp_read_composition_history_locked,
 * #823) and every surface that reads this key through the cache.
 * `_pp_composition_version` is single-valued by CONVENTION, not by schema: `wp_postmeta`
 * has no unique constraint on (post_id, meta_key), and add_post_meta(), an importer or a
 * hand-run `wp db query` appends a second row happily. get_post_meta($id, $k, true)
 * resolves to the FIRST row in meta_id order — that is what WordPress does TODAY
 * (update_meta_cache() selects ORDER BY meta_id ASC and get_metadata() takes element [0]),
 * observed behaviour rather than a documented API contract, which is exactly why it is
 * mirrored here rather than assumed. An unordered LIMIT 1 lets MySQL hand back any
 * qualifying row, so on a duplicated key this reader could compute the next version — and
 * answer the CAS — from a counter no other surface reads. Carrying the ordering is what
 * keeps the two agreeing; omitting it is the behaviour change. (update_metadata() then
 * UPDATEs every row for the key, so the next write collapses the duplicates; the ordering
 * only has to survive until then. The exposure is one write wide, and that one write is
 * the one that decides what the history ring preserves.)
 *
 * A FAILED READ IS NOT AN ABSENT ROW, AND THIS READER DOES NOT TELL THEM APART (#212).
 * $wpdb->get_var() returns null both for "no matching row" and for a query that FAILED
 * (killed query, statement timeout, lock-wait timeout) — the two are separable only via
 * $wpdb->last_error, which the ring reader below checks and this one does not. So a failed
 * SELECT reads as version 0 here, and at the caller that is not a cosmetic wrong answer:
 * `$next_version = 0 + 1` RESETS the monotonic counter, and a caller that supplied
 * `expected_version = 0` passes a CAS it should have failed. Left as found and filed
 * rather than patched here, because every honest fix changes a caller-visible outcome:
 * `int` cannot express "unreadable", so the reader would need a nullable return and
 * pp_update_composition() a new refusal, which is a posture ruling of the same family as
 * #821 rather than a line. Named here so the gap is stated instead of silent.
 *
 * @param object|null $wpdb     The DB handle inside the lock, or null in unit context.
 * @param int         $post_id  WordPress post ID.
 * @return int  The current version (0 if absent).
 */
function _pp_read_composition_version_locked($wpdb, int $post_id): int {
    if (is_object($wpdb) && method_exists($wpdb, 'get_var')) {
        $raw = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s ORDER BY meta_id ASC LIMIT 1",
                $post_id,
                '_pp_composition_version'
            )
        );
        return (int) $raw; // absent row → null → 0
    }
    return (int) get_post_meta($post_id, '_pp_composition_version', true);
}

/**
 * Reads the current stored composition JSON straight from the DB inside the composition
 * lock, bypassing the post-meta object cache (#133).
 *
 * The composition history ring (see pp_update_composition) must capture the EXACT prior
 * stored payload — the bytes a later restore replays — so it reads `_pp_composition`
 * directly, the same lost-update reasoning as _pp_read_composition_version_locked: the
 * pre-lock freshness check may have warmed a stale meta cache. Returns the raw JSON
 * string, or null when the row is absent (a brand-new page with no prior state to
 * preserve). Degrades to the cached read with no $wpdb (unit context).
 *
 * ORDER BY meta_id ASC, added by #825 for the reason its sibling above gives, and this is
 * the key where the disagreement was filed. `_pp_composition` is single-valued by
 * convention, not by schema; get_post_meta($id, $k, true) returns the FIRST row in meta_id
 * order (WordPress's behaviour today — update_meta_cache() selects ORDER BY meta_id ASC,
 * get_metadata() takes [0] — not a documented contract), and an unordered LIMIT 1 may hand
 * back a different one. The concrete damage on a page whose first row is healthy and whose
 * second row is corrupt bytes: pp_get_composition_result() and every surface built on it
 * (`wp pp check page`, `operate inspect-composition`, the render path) read the first row
 * and report the page healthy, while this reader could take the second, decide the prior
 * value did not decode, and push a PRESERVED-BYTES entry (#818) describing bytes nothing
 * else considers stored — a consumed ring slot and a `restorable: false` entry that
 * steps_back=1, the chat's undo selector, then refuses. The write itself still lands; what
 * breaks is the agreement between "what is stored" and "what was preserved", which is the
 * property #818 exists to guarantee. (As above, update_metadata() collapses duplicates on
 * the next write, so the window is one write wide — and this is the reader for whom that
 * one write is the whole job.)
 *
 * A FAILED READ IS NOT AN ABSENT ROW, AND THIS READER DOES NOT TELL THEM APART (#212).
 * The same null ambiguity the sibling above carries, with the stakes pointed the other
 * way: null here means "brand-new page, nothing to preserve", so a FAILED SELECT skips the
 * ring push entirely and the update_post_meta() below it overwrites `_pp_composition`
 * anyway — the prior state gone with no entry recording it, and on a corrupt page that is
 * the last recoverable copy of those bytes. It is the same wipe the ring reader's
 * last_error branch refuses one meta key over, arriving through a reader that never asked.
 * Left as found and filed rather than patched here: the honest fixes are "degrade to the
 * cached read" (which would preserve possibly-stale bytes AS the prior state) or "refuse
 * the write" (a new refusal on a public surface), and choosing between them is the posture
 * decision #821 tracks, not a line.
 *
 * @param object|null $wpdb     The DB handle inside the lock, or null in unit context.
 * @param int         $post_id  WordPress post ID.
 * @return string|null  The stored composition JSON, or null if absent.
 */
function _pp_read_composition_json_locked($wpdb, int $post_id): ?string {
    if (is_object($wpdb) && method_exists($wpdb, 'get_var')) {
        $raw = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s ORDER BY meta_id ASC LIMIT 1",
                $post_id,
                '_pp_composition'
            )
        );
        // '' means "no prior state", exactly as in the cached branch below (#818). The
        // two branches used to DISAGREE here: a SELECT against an existing-but-empty
        // `_pp_composition` row returns '', which this branch handed back as a real
        // value while the fallback mapped it to null. That was invisible while the
        // caller only asked "does this decode to an array?" — both answers led to no
        // push. Once an undecodable prior started being PRESERVED, the disagreement
        // became a 0-byte raw entry minted on production installs only: it consumes a
        // ring slot and makes steps_back=1 (the chat's undo selector) refuse. Empty
        // rows are routinely reachable — the `_pp_composition` sanitize_callback in
        // lib/admin.php rewrites any non-array payload to ''.
        return ($raw === null || (string) $raw === '') ? null : (string) $raw;
    }
    $raw = get_post_meta($post_id, '_pp_composition', true);
    // get_post_meta(single=true) returns '' for an absent key; treat only genuine
    // absence as "no prior state", matching pp_get_composition_result's guard.
    return ($raw === '' || $raw === false || $raw === null) ? null : (string) $raw;
}

/**
 * Reads the composition history ring straight from the DB inside the composition lock,
 * bypassing the post-meta object cache (#823). The third sibling of the two readers
 * above, and it exists for the same reason they do.
 *
 * THE LOSS IT CLOSES, which is a lost UNDO STEP rather than a lost counter. The ring is
 * rebuilt read-modify-write inside pp_update_composition()'s per-post advisory lock. The
 * lock serialized the WRITES and did nothing about the READ, so a request that warmed the
 * post's meta cache BEFORE the lock rebuilt the ring from that pre-lock copy:
 *
 *     A: reads the page (ring […, e1] now warm in A's request cache)
 *     A: calls pp_update_composition(), blocks in GET_LOCK
 *     B: holds the lock, appends e2, writes […, e1, e2], releases
 *     A: acquires, re-reads version and composition FROM THE DB (correctly), rebuilds the
 *        ring from its STALE […, e1], appends e3, writes […, e1, e3]        ← e2 is gone
 *
 * No persistent object cache is needed: core's WP_Object_Cache is per-request, and
 * update_metadata()'s wp_cache_delete() only clears the WRITING process's copy. Nor does
 * the warm-up need to be deliberate — update_meta_cache() loads EVERY meta_key for the
 * post in one query, so any earlier get_post_meta() on the post (pp_get_composition_result()
 * runs on essentially every path that leads to a write) warms this key too. On the restore
 * path it is guaranteed: lib/actions.php calls pp_get_composition_history() immediately
 * before pp_update_composition().
 *
 * Since #818 the dropped entry can be the PRESERVED-BYTES entry for a corrupt page — the
 * only recoverable copy of those bytes, rescued at the repair write and dropped one
 * concurrent write later, silently.
 *
 * NO $wpdb PARAMETER, unlike the two siblings above, and that asymmetry is deliberate.
 * Since #767 the question "can we do an uncached read at all?" has a SINGLE OWNER,
 * pp_composition_db_handle(), which reads the global itself — and the handle
 * _pp_with_advisory_lock() passes to the mutator is the same global it captured. Taking a
 * parameter and then answering the capability question from the global would give this
 * function two sources of truth for one handle; re-spelling the four clauses locally would
 * fork the owner. The four-clause guard is strictly stronger than the siblings' two-clause
 * one: a partial handle (several test doubles implement get_var and nothing else) yields an
 * empty table name and a query that cannot be trusted.
 *
 * TWO ASSUMPTIONS THIS READ RESTS ON, stated as assumptions because nothing here enforces
 * them, and both predate #823 — every reader and writer inside the lock shares them:
 *
 *   SAME CONNECTION.  Advisory locks are connection-scoped, so a read over a different
 *                     connection is a read outside the lock. Two things could move it:
 *                     third-party code reassigning $GLOBALS['wpdb'] mid-request (a db.php
 *                     drop-in, a multi-network plugin — nothing in this theme does), and
 *                     wpdb's own errno-2006 self-heal, which re-runs a statement on a NEW
 *                     connection after check_connection() and reports success with an empty
 *                     last_error. The reconnect silently voids the GET_LOCK for the whole
 *                     rest of the mutator, not just this read; tracked as its own issue
 *                     rather than patched here.
 *   FRESH SNAPSHOT.   InnoDB defaults to REPEATABLE READ, and GET_LOCK neither commits nor
 *                     refreshes a read view. Under vanilla WordPress autocommit every
 *                     statement gets a fresh snapshot and this read sees the concurrent
 *                     writer's committed ring. Inside an explicit transaction opened earlier
 *                     on the same connection by other code, it would be served from a
 *                     pre-lock snapshot — authoritative in shape, stale in fact.
 *
 * ORDER BY meta_id ASC, which all four locked readers now carry — this one had it first,
 * #825 brought the version and json readers onto it, and #828's hash reader arrived
 * carrying it.
 * `_pp_composition_history` is single-valued by convention, not by schema, and
 * get_post_meta($id, $k, true) returns the FIRST row in meta_id order (update_meta_cache
 * selects ORDER BY meta_id ASC, get_metadata takes [0]). So the ordering is what makes this
 * read the SAME row the cached path reads — omitting it is what would change behavior on a
 * duplicated key, not carrying it. (update_metadata() then updates every row for the key,
 * so a write collapses duplicates; the ordering only has to survive until then.)
 *
 * THE WRITE BELOW LEAVES THE REQUEST'S CACHE CORRECT, which the filed issue asked to be
 * confirmed rather than assumed. update_metadata() deletes the post's whole `post_meta`
 * cache entry after a successful UPDATE (wp-includes/meta.php, verified against WP 7.0:
 * `wp_cache_delete( $object_id, $meta_type . '_meta' )` at both the add and update paths),
 * so a later get_post_meta() in the same request re-primes from the row this write just
 * committed. Nothing here has to prime the cache by hand, and a caller reading the ring
 * after the write sees the ring that was written.
 *
 * A FAILED READ FALLS BACK TO THE CACHE, and the honest way to say it is that this fix
 * closes the stale-read window only when the uncached read SUCCEEDS. The alternative is
 * worse in the one direction that matters here: $wpdb->get_var() returns null both for
 * "no row" and for a failed query (#212), so treating a DB error as an empty ring would
 * WIPE up to pp_composition_history_max() entries on the very write that was supposed to
 * preserve one — a new data-loss path opened by a data-loss fix. Falling back leaves a
 * failed read behaving exactly as this code behaved before #823. That a skipped or degraded
 * ring push still lets the composition overwrite proceed is the wider posture question
 * tracked in #821.
 *
 * @param int $post_id  WordPress post ID.
 * @return array  Normalized ring entries, oldest first (see pp_get_composition_history).
 */
function _pp_read_composition_history_locked(int $post_id): array {
    $wpdb = pp_composition_db_handle();
    if ($wpdb === null) {
        // Unit context: no database to ask, and no cross-process store for a cached read
        // to be stale against. Production WordPress always has a usable handle.
        return pp_get_composition_history($post_id);
    }

    $raw = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s ORDER BY meta_id ASC LIMIT 1",
            $post_id,
            '_pp_composition_history'
        )
    );
    // #212's disambiguation, and it must precede the null handling below: a failed query
    // also returns null, and must not be read as "this page has no history". It covers the
    // query-level failures (killed query, statement timeout, lock wait) — wpdb::query()
    // flushes last_error to '' at the start of every query and sets it on error. It does
    // NOT cover the two branches where wpdb bails before running anything (`!$this->ready`,
    // a failed check_connection()), which return null with last_error empty. Left as the
    // sibling _pp_read_token_overrides_locked_strict() leaves it: both require a dead
    // connection, and the update_post_meta() below would fail on the same connection, so
    // the short ring is never persisted.
    if (!empty($wpdb->last_error)) {
        return _pp_degraded_history_ring($post_id, 'the authoritative read failed (' . $wpdb->last_error . ')');
    }
    // maybe_unserialize() is the step get_metadata() takes on the cached path. A ring row
    // written as a PHP-serialized array (an importer, a post-duplicator, `wp post meta
    // update ... --format=json`) is a readable ring to get_post_meta() and opaque bytes to
    // a raw column read; without this the two readers would disagree about which entries
    // exist, which is the disagreement this reader was added to remove.
    $value = ($raw === null) ? '' : maybe_unserialize($raw);

    // A ROW THAT IS PRESENT BUT NOT A RING IS DAMAGED, NOT EMPTY — and this is the only
    // place in the codebase that can tell the two apart, because it is the only one holding
    // the raw bytes. The general reader collapses both to [] and is right to; here [] would
    // be persisted: the rebuild below appends one entry to it and writes the result, so a
    // single truncated write to this row would take the other nine slots with it, on the
    // write path #818 built to keep the last copy of a corrupt page's bytes. That is the
    // same wipe the last_error branch above refuses, arriving through a SUCCESSFUL read, so
    // it gets the same answer. `_pp_composition_history` is the row most exposed to it: same
    // column and same writer as `_pp_composition` (whose rows demonstrably do go
    // undecodable — that is why #818 exists), carrying up to ten compositions rather than
    // one.
    $entries = _pp_decode_history_ring($value);
    if ($entries === null) {
        return _pp_degraded_history_ring($post_id, 'the stored ring row is present but did not decode to a list');
    }
    return _pp_normalize_history_ring($entries);
}

/**
 * The locked reader's fallback: the cached ring, plus a line in the log saying why (#823).
 *
 * A BREADCRUMB IS NOT A POSTURE, and this file already settled that argument for the
 * strictly milder degradation below it (a skipped ring write, which at least leaves the
 * previous ring intact). This one is worse and had no breadcrumb at all: the write proceeds,
 * persists a ring rebuilt from a possibly-stale copy, and reads in every log exactly like a
 * healthy write. Falling back is still right — a stale ring beats a wiped one, and it is
 * what this code did before #823 — but a fix whose premise is "the only recoverable copy of
 * those bytes" must not fail silently back into the bug it closes.
 *
 * NOT LOGGED, deliberately: the no-usable-handle branch. That one is the documented
 * unit-context path (production always satisfies every clause of pp_composition_db_handle()),
 * so a line there would fire on every composition write in the test suite and say nothing
 * about production.
 *
 * @param int    $post_id  WordPress post ID.
 * @param string $reason   What went wrong, in words, for the log line.
 * @return array  The cached ring, normalized as usual.
 */
function _pp_degraded_history_ring(int $post_id, string $reason): array {
    error_log(
        'PromptingPress: composition post ' . $post_id
        . ' history ring read fell back to the object cache (' . $reason
        . '); a concurrent write\'s entry may be dropped from the ring by this write.'
    );
    return pp_get_composition_history($post_id);
}

/**
 * Reads the freshness content-hash straight from the DB inside the composition lock (#828).
 * The fourth sibling of the three readers above, and the last of pp_update_composition()'s
 * four in-lock reads to stop asking the object cache.
 *
 * WHAT IT IS AND WHAT IT IS NOT, because the honest case for this change rests on it. The
 * `hash` on a history-ring entry is PROVENANCE — the marker of the state that entry
 * preserves, as pp_get_composition_history()'s docblock says — and NOT a checksum of the
 * entry's payload. Nothing in the codebase reads it today. So this is not a data-loss fix
 * the way #823 was, and the issue that filed it said so first.
 *
 * WHY IT IS WORTH DOING ANYWAY, in two parts:
 *
 *   THE PAIR COULD DESCRIBE DIFFERENT MOMENTS. `version` on the same entry has been
 *   authoritative since #113; `hash` was not. The warm-up path is the one #823 documents —
 *   update_meta_cache() loads EVERY meta_key for a post in one query, so any earlier
 *   get_post_meta() on the post warms `_pp_composition_hash` too, and a concurrent write
 *   landing after that warm-up leaves the cached copy describing a state that is no longer
 *   current. Both markers stale together is a coherent, if old, description of a real
 *   moment. One fresh and one stale is a {version, hash} pair that never existed, which is
 *   strictly worse for anything that ever cross-checks them.
 *
 *   THREE OF FOUR IS AN OVERSIGHT-SHAPED ASYMMETRY. Whichever way it was meant, the next
 *   person in this block reads it as one. The alternative #828 offered was to state in the
 *   code why this one deliberately stayed cached; there was no such reason, so it joins its
 *   siblings instead.
 *
 * BEHAVIOUR-NEUTRAL FOR WELL-FORMED FLOWS, and pinned that way. On an uncontended write the
 * row and the cache hold the same bytes, so the entry is unchanged field for field. What
 * moves is the contended case, which is the case this exists for.
 *
 * PARITY WITH THE CACHED READ IT REPLACES, spelled out because "behaviour-neutral" is a
 * claim about edges, not about the happy path:
 *
 *   absent row            get_var() → null, get_post_meta(single) → ''. Mapped to '' here,
 *                         so a page with no marker yet reads the same both ways.
 *   maybe_unserialize()   the step get_metadata() takes on the way out, which a raw column
 *                         read does not. A serialized row is a readable value to
 *                         get_post_meta() and opaque bytes to a direct read; the ring reader
 *                         above takes this step for the same reason.
 *   (string) cast         left at the CALL SITE's historical spelling, applied here so the
 *                         signature is honest. A non-scalar stored value therefore behaves
 *                         exactly as it did before #828 — "Array" plus a warning for an
 *                         array, an uncaught Error for an object without __toString(),
 *                         both of which the replaced `(string) get_post_meta(...)` produced
 *                         on the same bytes, because get_metadata() unserializes before the
 *                         call site casts. Parity includes the ugly cases, or it is not
 *                         parity.
 *   the `get_post_metadata` filter   NOT parity, and deliberately so: get_post_meta() fires
 *                         it and a column read does not, which is what "authoritative"
 *                         means — the three siblings bypass it for the same reason. Stated
 *                         because the consequence is not obvious: the two degradation
 *                         branches below go through get_post_meta(), so on an install whose
 *                         plugin filters this key, a DEGRADED read returns the filtered
 *                         value and a healthy one returns the row. Harmless here (the value
 *                         is inert provenance) and worth knowing before this pattern is
 *                         copied to a slot where it is not.
 *
 * ORDER BY meta_id ASC, for the reason #825 gives on the other three keys: `_pp_composition_hash`
 * is single-valued by convention, not by schema, so a duplicated key must resolve to the
 * same FIRST row in meta_id order that get_post_meta($id, $k, true) returns. A marker read
 * off a row no other surface reads would be a new disagreement introduced by a fix for
 * disagreement.
 *
 * NO $wpdb PARAMETER, matching _pp_read_composition_history_locked() rather than the two
 * older siblings: pp_composition_db_handle() is the single owner of "can we do an uncached
 * read at all?", its four clauses are strictly stronger than their two, and the handle
 * _pp_with_advisory_lock() passes the mutator is the same global it captured. New code
 * takes the owner.
 *
 * SAME CONNECTION, and it is worth spelling out because taking the handle from the global
 * rather than from the mutator's parameter LOOKS like it could read outside the lock.
 * _pp_with_advisory_lock() does `global $wpdb`, issues GET_LOCK on that object, and passes
 * THAT SAME OBJECT to the mutator; pp_composition_db_handle() does `global $wpdb` and
 * returns it. Same object, same wpdb instance, same MySQL connection — so the
 * connection-scoped lock covers this read. The two ways that pairing could break are
 * pre-existing and shared with every reader in this block, not introduced by taking the
 * global: third-party code reassigning $GLOBALS['wpdb'] mid-request (nothing in this theme
 * does), and wpdb's errno-2006 self-heal, which reconnects and thereby releases the lock
 * for the whole rest of the mutator — tracked as #830, whose fix belongs in
 * _pp_with_advisory_lock() rather than in any one reader.
 *
 * A FAILED READ FALLS BACK TO THE CACHE (#212). $wpdb->get_var() returns null for both "no
 * row" and "the query failed", so without the last_error check a lock-wait timeout on an
 * otherwise-healthy install would mint an entry whose marker is '' — a provenance value
 * that never existed, where the cache still holds the real one. The guarantee is PARITY
 * WITH THE OLD BEHAVIOUR, not a non-empty marker: against a wholly dead connection the
 * fallback's own read fails too and returns '', exactly as the pre-#828 call site did. The
 * floor is "no worse than before", which is the honest claim. Unlike the ring reader, no
 * log line: this degradation costs a marker's freshness, not a ring, and the write path
 * already logs the failures that cost data.
 *
 * @param int $post_id  WordPress post ID.
 * @return string  The stored content-hash marker, or '' when absent.
 */
function _pp_read_composition_hash_locked(int $post_id): string {
    $wpdb = pp_composition_db_handle();
    if ($wpdb === null) {
        // Unit context: no database to ask, and no cross-process store for a cached read to
        // be stale against. Production WordPress always has a usable handle.
        return (string) get_post_meta($post_id, '_pp_composition_hash', true);
    }

    $raw = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s ORDER BY meta_id ASC LIMIT 1",
            $post_id,
            '_pp_composition_hash'
        )
    );
    // Must precede the ($raw === null) handling below: a failed query also returns null and
    // must not be read as "this page has no marker" (#212).
    if (!empty($wpdb->last_error)) {
        return (string) get_post_meta($post_id, '_pp_composition_hash', true);
    }

    return (string) ($raw === null ? '' : maybe_unserialize($raw));
}

/**
 * Computes the freshness content-hash of a composition (#113).
 *
 * Hashes the CANONICAL pre-stable-id form: the auto-generated top-level props.id (the
 * only field pp_update_composition() injects) is stripped before hashing, so the hash is
 * stable across the id-injection round-trip — a composition written without ids hashes
 * the same as the same composition re-read WITH its injected ids. Without this, every
 * write would false-conflict against itself.
 *
 * The marker compares two hashes both produced HERE at write time (never a rebuilt one),
 * so byte-stable canonicalization beyond the id strip is not required for correctness.
 *
 * @param array $composition  Array of component objects.
 * @return string  A 64-char sha256 hex digest.
 */
function pp_composition_content_hash(array $composition): string {
    $canonical = array_map(function ($item) {
        if (is_array($item) && isset($item['props']) && is_array($item['props'])) {
            unset($item['props']['id']);
        }
        return $item;
    }, $composition);
    return hash('sha256', (string) wp_json_encode($canonical, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

/**
 * Returns the freshness marker for a post's composition (#113).
 *
 * The marker is the {version, hash} pair written by pp_update_composition() as sibling
 * post meta. An absent marker (a page never written through pp_update_composition, or a
 * legacy page) reads as version 0 / hash '' — the first write initializes version 1.
 * Strict casts at this boundary guarantee the caller always gets an int + a string, so a
 * downstream hash_equals() never sees a non-string.
 *
 * @param int $post_id  WordPress post ID.
 * @return array{version: int, hash: string}
 */
function pp_get_composition_marker(int $post_id): array {
    return [
        'version' => (int) get_post_meta($post_id, '_pp_composition_version', true),
        'hash'    => (string) get_post_meta($post_id, '_pp_composition_hash', true),
    ];
}

// ── Composition history ring (#133) ─────────────────────────────────────────
// Design-token writes earned a full snapshot/restore subsystem; composition
// writes (page content) had none — a replaced or component-removed composition
// was lost permanently. Every composition write now pushes the PRIOR state onto
// a bounded per-post history meta so an operator/AI can restore it. The push
// happens inside pp_update_composition's per-post advisory lock, alongside the
// #113 marker bump, so history stays consistent with the version counter.
//
//   pp_update_composition(post, C_new)
//     └─ [lock] read prior JSON J_prior ──┬─ decodes to a LIST ────► push {ts, version,
//                                         │                          hash, composition}
//                                         └─ does NOT ────────────► push {ts, version,
//                                                                    hash, raw_b64}
//                write C_new, bump marker      onto _pp_composition_history (ring, last N)
//
// restore_composition (lib/actions.php) reads this ring and re-writes a chosen
// entry's composition back through pp_update_composition — so a restore is
// itself a conflict-checked write that lands its own history entry.
//
// #818: EVERY prior state gets a ring slot, including one whose stored bytes do
// not decode to a composition. Those push a raw entry carrying the bytes verbatim
// instead of a `composition` entry — preserved, addressable and printable, but
// NOT replayable. Before #818 they were not pushed at all, so the repair write
// for a corrupt page destroyed the only recoverable copy of it.
//
// #841: "decodes to a composition" means decodes to a LIST, not merely to an
// array. A JSON OBJECT decodes to a PHP associative array, so the older
// is_array() test filed one as a replayable snapshot and replaying it fataled.
// The test is now pp_is_list() at BOTH ends — the push above, and
// _pp_normalize_history_ring() for rings written before the fix.

/**
 * Maximum number of prior-composition snapshots retained per post (#133).
 *
 * A bounded ring: the Nth-oldest entry is evicted when a newer write pushes past N.
 * Fixed (not configurable) — 10 covers realistic undo depth without unbounded meta
 * growth on a hot page.
 *
 * @return int
 */
function pp_composition_history_max(): int {
    return 10;
}

/**
 * Is this history-ring entry a PRESERVED-BYTES record rather than a composition
 * snapshot (#818)?
 *
 * The one predicate every ring reader uses to tell the two entry forms apart. Written
 * against the NORMALIZED shape pp_get_composition_history() returns, where the two forms
 * are mutually exclusive: a raw entry carries `raw` (a string) and no `composition`, a
 * snapshot carries `composition` (an array) and no `raw`.
 *
 * TRUE MEANS "NOT REPLAYABLE, CARRIES BYTES" — it does not, on its own, promise those bytes
 * are the page's original ones. They are, for every raw entry a push minted. For a pre-#841
 * object row that _pp_normalize_history_ring() reclassified they are the ring's decoded copy
 * re-encoded; see pp_get_composition_history() for that caveat and who it reaches.
 *
 * @param array $entry  A single normalized entry, from either ring reader — see
 *                      pp_get_composition_history() for the shape.
 * @return bool  True for a preserved-bytes entry.
 */
function pp_history_entry_is_raw(array $entry): bool {
    return isset($entry['raw']) && is_string($entry['raw']);
}

/**
 * Is this decoded payload a REPLAYABLE composition snapshot (#841)?
 *
 * ONE OWNER FOR THE QUESTION #841 WAS ABOUT. Two places in the ring have to agree on it:
 * pp_update_composition()'s push, deciding which entry shape a prior state gets, and
 * _pp_normalize_history_ring(), deciding which shape a STORED entry reads back as. The
 * defect was those two ends holding different opinions — the push accepted any array, so a
 * decoded JSON OBJECT was filed and read back as replayable, and replaying it drove the
 * id-injection loop onto a string band. Spelling the test twice is how that happens again;
 * spelling it once makes the agreement structural rather than a promise in two comments.
 *
 * Deliberately NOT pp_history_entry_is_raw()'s counterpart: that predicate answers "which
 * form is this ALREADY-NORMALIZED entry?", while this one judges a raw payload — a fresh
 * json_decode of stored bytes (so possibly null, an int, a string) or a stored entry's
 * `composition` value, which a hand-edited row could set to anything.
 *
 * The composition contract is a LIST (pp_validate_composition_errors() refuses a non-list
 * container, #724), so this asks pp_is_list() and nothing more. It judges the CONTAINER
 * only: whether the elements inside it are usable components is a separate question, and
 * one this predicate deliberately does not answer (see #842).
 *
 * @param mixed $payload  A decoded composition candidate.
 * @return bool  True when it may be replayed as a composition.
 */
function _pp_history_payload_is_snapshot($payload): bool {
    return is_array($payload) && pp_is_list($payload);
}

/**
 * Returns the composition history ring for a post, newest-last (#133).
 *
 * TWO ENTRY FORMS since #818, and a reader must handle both:
 *
 *   SNAPSHOT  {timestamp:int, version:int, hash:string, composition:array}
 *             The prior composition, as it was BEFORE the write that pushed the entry.
 *             restore_composition replays this verbatim (#233).
 *
 *   RAW       {timestamp:int, version:int, hash:string, raw:string}
 *             The prior STORED BYTES, EXACTLY, when they did not decode to a COMPOSITION —
 *             a page classified decode_error, or either sub-case of unexpected_shape: the
 *             valid-JSON SCALAR (`null`, `5`, `"text"`) and, since #841, the valid-JSON
 *             OBJECT (`{"component":…}`, `{"a":…,"b":…}`). See pp_get_composition_result.
 *             NOT the folded numeric object `{"0":…,"1":…}`, which json_decode hands back
 *             as a genuine PHP LIST — no code below the decode can tell it from an
 *             authored array, so it is snapshotted like one (pp_is_list(), and
 *             CompositionShapeTrustTest pins that it is accepted).
 *             Before #818 these were not pushed AT ALL, so the write that replaced them
 *             destroyed the only recoverable copy. They are preserved instead, but they
 *             are NOT a composition and must never be treated as one: restore_composition
 *             refuses to replay a raw entry (`history_entry_not_restorable`) and
 *             `wp pp operate composition-history` prints the bytes so an operator can
 *             recover them by hand. Use pp_history_entry_is_raw() to branch.
 *
 * The two forms are mutually exclusive and the discriminating key is always present.
 *
 * ONE RAW ENTRY IS NOT BYTE-EXACT, AND IT IS THE ONE THIS DOCBLOCK OWES YOU (#841). A ring
 * written before #841 holds OBJECT-shaped priors filed as `composition` entries, because the
 * push tested is_array() and a JSON object decodes to a PHP associative array. Those rows are
 * reclassified to RAW on the way out of _pp_normalize_history_ring(), which is what stops a
 * restore of one from fataling — but their `raw` is that decoded object RE-ENCODED, not the
 * bytes the page stored. The stored bytes for that class are already gone: the pre-#841 push
 * kept a decoded array and nothing else, so whitespace, key ORDER, duplicate keys, number
 * spelling and escape form were lost at that push, not here. Every raw entry a CURRENT push
 * mints is byte-exact, including the object class; only this migration path is a re-encode.
 * It matters most for `raw_sha256` in the CLI listing, which is a transfer digest either way
 * (see PP_Operate_Command::composition_history) and for a reclassified row is a digest of the
 * re-encode, never a proof about what the page originally stored.
 *
 * The reclassification is a READ, so the stored row keeps its old shape until something
 * writes the ring again — at which point the append path in pp_update_composition() persists
 * the reclassified form through _pp_history_entries_for_storage() and the migration becomes
 * durable. Anything reading `_pp_composition_history` WITHOUT this normalizer therefore still
 * sees the mis-filed `composition` key on an un-rewritten page. Nothing in this codebase does
 * that (the two ring readers both come through here); a plugin or a hand query could.
 *
 * ON DISK A RAW ENTRY IS BASE64, AND THAT IS LOAD-BEARING, NOT TIDINESS. The ring is
 * persisted as JSON, and JSON IS NOT A BYTE CONTAINER. Malformed UTF-8 is one of the
 * corruptions pp_get_composition_result() names as a decode_error cause, and storing
 * those bytes verbatim breaks the ring encode in one of two ways depending on which
 * encoder runs — both fatal to the point of the entry:
 *
 *   json_encode()      returns FALSE outright (`Malformed UTF-8 characters`). The meta
 *                      write would then persist that false over the WHOLE ring, so the
 *                      fix for losing one page's bytes would lose all ten entries.
 *   wp_json_encode()   does NOT fail: on a false return it runs _wp_json_sanity_check()
 *                      / _wp_json_convert_string(), which coerce the string to valid
 *                      UTF-8 and re-encode. It SUCCEEDS, having silently substituted the
 *                      exact bytes this entry exists to preserve. A lossy copy that
 *                      reports success is the worse of the two.
 *
 * So the stored key is `raw_b64` — pure ASCII, which no encoder has to touch — and THIS
 * function hands callers the decoded bytes back under `raw`. Callers never see base64;
 * every write of the ring converts back through _pp_history_entries_for_storage().
 *
 * `version` and `hash` mean THE MARKER AS IT STOOD when the entry was pushed, in both
 * forms — not "the version/hash OF this payload". For a snapshot those coincide, because
 * the marker was written by the same call that stored the composition. For a RAW entry
 * they do not, and deliberately so: bytes that reached `_pp_composition` without going
 * through pp_update_composition() leave the marker describing the last composition that
 * DID, which is itself the tell that the corruption bypassed the writer. Nothing in the
 * codebase reads `hash` off an entry today; read it as provenance, never as a checksum of
 * `raw`.
 *
 * SLOT PRESSURE, ACCEPTED (#818), AND IT CUTS BOTH WAYS. A raw entry consumes a ring
 * slot that a composition snapshot would otherwise hold, so a page corrupted and repaired
 * N times evicts good snapshots N slots faster than before. The other direction matters
 * more and is easier to miss: ORDINARY WRITES EVICT THE RAW ENTRY. A preserved-bytes slot
 * has a hard, silent lifetime of pp_composition_history_max() further writes on that page
 * — and a restore is itself a write — after which the bytes are gone for good. Nothing
 * warns the operator that the clock is running, so the recovery window is real but finite:
 * read the bytes out soon after the repair.
 *
 * Both directions are the deliberate trade. The ruling is that the bytes a repair replaces
 * must stay recoverable, and dropping them at the push to protect older snapshots is the
 * destroying-the-only-copy behavior #818 closes. No separate cap or exemption for raw
 * entries — a second bound with its own number is how one ring starts having two policies.
 *
 * Defensive like pp_get_composition_result: an absent, non-JSON, non-list, or shape-wrong
 * meta row degrades to [] (no history) rather than fataling, and an individual entry that
 * matches NEITHER form is dropped — as it always was. Callers get a clean list they can
 * index or walk backwards.
 *
 * THIS IS THE CACHED READER, which is the right one everywhere except inside the write
 * lock. The rebuild in pp_update_composition() reads through
 * _pp_read_composition_history_locked() instead (#823) — same normalization, asked of the
 * database — because a cached ring there loses a concurrent writer's entry. Both share
 * _pp_normalize_history_ring(), so they cannot drift on which entries are readable.
 *
 * @param int $post_id  WordPress post ID.
 * @return array  List of history entries, oldest first, or [] if none/unreadable.
 */
function pp_get_composition_history(int $post_id): array {
    return _pp_normalize_history_ring(get_post_meta($post_id, '_pp_composition_history', true));
}

/**
 * Normalizes a STORED `_pp_composition_history` value into the ring shape every reader
 * sees (#133, raw entries #818, extracted #823).
 *
 * Extracted verbatim from pp_get_composition_history() when #823 added a second reader
 * — _pp_read_composition_history_locked(), which asks the DATABASE the same question
 * inside the write lock. The two readers differ in WHERE the bytes come from and in
 * nothing else, and that is the point: the ring's whole job is losing nothing, so a
 * cached reader and an authoritative reader that disagreed about which entries are
 * readable would be a silent loss of exactly the entries only one of them can see.
 * One normalizer makes the agreement structural instead of a promise.
 *
 * Accepts what BOTH sources can hand back: get_post_meta() unserializes on the way out
 * (so an array arrives already decoded), a column read does not (so a JSON string
 * arrives, or an array after maybe_unserialize). Everything else — entry forms, the
 * base64 decode, the drop rules — is documented on pp_get_composition_history(), which
 * remains the reference for the shape this returns.
 *
 * @param mixed $raw  The stored meta value: a JSON string, an already-decoded array, or an
 *                    absent-row marker ('', null, false).
 * @return array  List of normalized entries, oldest first, or [] if none/unreadable.
 */
function _pp_normalize_history_ring($raw): array {
    $entries = _pp_decode_history_ring($raw);
    if ($entries === null) {
        return [];
    }
    $clean = [];
    foreach ($entries as $index => $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $common = [
            'timestamp' => (int) ($entry['timestamp'] ?? 0),
            'version'   => (int) ($entry['version'] ?? 0),
            'hash'      => (string) ($entry['hash'] ?? ''),
        ];
        // Snapshot first: `composition` is the older form and the overwhelmingly common
        // one, and an entry carrying both keys — which this writer never produces, so it
        // means a hand-edited row — is read as the replayable half. STATED LIMIT: that
        // preference is wrong when the replayable half is EMPTY, where it yields a
        // restorable no-op snapshot and the bytes beside it are dropped. Reachable only by
        // writing the meta directly; not defended against, because guessing which half a
        // hand-edit meant is worse than picking the documented one.
        //
        // SINCE #841 THE SNAPSHOT BRANCH ASKS pp_is_list(), the same question the
        // composition contract asks (#724) and the same one the push below now asks. So
        // "the replayable half" means a LIST — a `composition` holding a JSON object falls
        // past this branch to the reclassification at the bottom of the loop, and a
        // both-keys row whose composition is an object reads as the preserved BYTES rather
        // than the object. That is the both-keys preference working, not an exception to
        // it: the preference is for the half that can actually be replayed.
        //
        // This is where the invariant pp_history_entry_is_raw() relies on is ESTABLISHED,
        // which is why the raw test is spelled out here rather than delegated to it: the
        // predicate answers "which form is this?" for an already-normalized entry, while
        // the branches below decide the form from arbitrary stored bytes, where `raw`
        // could be anything at all. Three of them since #841: snapshot, preserved bytes,
        // and the reclassification of a row the pre-#841 push mis-filed as a snapshot.
        if (isset($entry['composition']) && _pp_history_payload_is_snapshot($entry['composition'])) {
            $clean[] = $common + ['composition' => $entry['composition']];
            continue;
        }

        // NOT A SNAPSHOT, so the row is a preserved-bytes candidate and the only question
        // left is where its bytes come from. Decoded BEFORE the branch, not inside it, so a
        // row whose `raw_b64` is not real base64 can still fall through to the #841
        // reclassification below instead of consuming the row and yielding nothing. Strict
        // decode either way: handing back base64_decode()'s lenient garbage would be worse
        // than having no bytes at all — the whole point of this entry is that they are EXACT.
        $bytes = (isset($entry['raw_b64']) && is_string($entry['raw_b64']))
            ? base64_decode($entry['raw_b64'], true)
            : false;

        if ($bytes !== false) {
            $clean[] = $common + ['raw' => $bytes];
        } elseif (isset($entry['composition']) && is_array($entry['composition'])) {
            // THE LEGACY ROW #841 HAS TO ANSWER FOR. `composition` is an array but not a
            // LIST, so it is a JSON object and never was a composition — the push branch
            // below filed it as one because it keyed on bare is_array(), and replaying it
            // reached pp_update_composition()'s id-injection loop with a string where a
            // band should be (uncaught TypeError). Rings written before #841 hold these,
            // and the ring meta is raw-writable besides, so refusing at the RESOLVER
            // would leave this reader still calling the row restorable. Answering it HERE
            // keeps one owner for "which form is this entry" and hands the whole #818
            // machinery — restorable:false, components:null, raw/raw_base64/raw_sha256,
            // the history_entry_not_restorable refusal — the shape it already knows.
            //
            // STATED LIMIT, AND IT IS A REAL ONE: for a row reclassified here `raw` is
            // this ring's DECODED copy re-encoded, not the bytes the page stored. The
            // ring never kept those for this class — the pre-#841 push stored a decoded
            // array — so the exact original bytes are already gone and no reader can
            // conjure them. Everything a new push preserves is still byte-exact; only
            // this migration path is a re-encode, which is why it is documented on
            // pp_get_composition_history() rather than left for a reader to infer from
            // a raw_sha256 that would otherwise look like a preservation digest.
            //
            // json_encode(), NOT wp_json_encode(), AND THAT IS THE SAME RULING AS #818's,
            // applied to the other end of the pipe. This function's own contract (see
            // pp_get_composition_history) is that wp_json_encode does not fail on a payload
            // it cannot represent: it runs _wp_json_sanity_check() / _wp_json_convert_string(),
            // coerces the bytes and SUCCEEDS, "having silently substituted the exact bytes
            // this entry exists to preserve. A lossy copy that reports success is the worse
            // of the two." That verdict is about a RECOVERY payload, which is exactly what
            // this is. The objection that made #818 choose base64 over a loud failure does
            // not apply here: this encode covers ONE entry's payload, so a false return
            // costs that row's bytes, never the other nine slots.
            //
            // Reachable because the normalizer accepts an ALREADY-DECODED ring (documented
            // above; get_post_meta unserializes, and the locked reader maybe_unserialize()s),
            // and a serialized row never passed a json_decode — so it can carry invalid
            // UTF-8, INF/NAN or a resource that no encoder will take.
            //
            // Same flags as every other composition encode in this file, so a legacy row
            // reads back the way the same document would have been stored today.
            //
            // ORDERED AFTER raw_b64 ON PURPOSE, and after a SUCCESSFUL decode of it: a
            // hand-edited row carrying BOTH a non-list `composition` and real preserved
            // bytes hands back the BYTES. That is a deliberate narrowing of the both-keys
            // preference noted above — the preference is for the REPLAYABLE half, and a
            // JSON object is not replayable, so preferring it over exact bytes would trade
            // a recovery for a refusal. When the bytes turn out to be unreadable, the
            // object is the only recovery left and the row falls through to here rather
            // than losing both halves.
            $encoded = json_encode(
                $entry['composition'],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
            if (is_string($encoded)) {
                $clean[] = $common + ['raw' => $encoded];
                continue;
            }

            // THE SLOT SURVIVES EVEN WHEN ITS PAYLOAD DOES NOT, and that is a decision, not
            // a leftover. Dropping the row here would not merely hide it: the append path
            // in pp_update_composition() rebuilds the stored ring from what this function
            // returns, so a dropped row is DELETED by the next composition write — turning
            // a read-time degradation into permanent data destruction, which is the #818
            // behavior this whole area exists to end. It would also shift `history_index`
            // and `steps_back` for every neighbouring slot, and those are positional
            // selectors an operator types from an earlier listing: _pp_resolve_history_target()
            // promises an unreplayable slot "stays ADDRESSABLE ... so history_index and
            // steps_back keep counting writes truthfully". A zero-byte raw entry keeps that
            // promise, refuses like any other raw row, and is neither the fatal nor a silent
            // deletion. The breadcrumb is the other half: a slot that reads as empty for a
            // reason nobody can see is the gap the sibling guard below already refuses to
            // leave.
            error_log(
                'PromptingPress: composition history entry ' . $index . ' holds a non-list '
                . '`composition` that could not be re-encoded (' . json_last_error_msg()
                . '); the ring slot was kept as an empty preserved-bytes entry so it stays '
                . 'addressable, but its payload is not recoverable through '
                . '`wp pp operate composition-history`. Read `_pp_composition_history` directly.'
            );
            $clean[] = $common + ['raw' => ''];
        }
    }
    return $clean;
}

/**
 * Decodes a stored ring value to its raw entry list, distinguishing ABSENT from UNREADABLE
 * (#133, #823).
 *
 * One owner for "is this stored value a ring at all?", because two readers ask it and one of
 * them can ACT on the difference. `_pp_normalize_history_ring()` collapses both answers to []
 * — the right degradation for a general reader, which has nothing better to return. The locked
 * reader cannot: it is about to persist a ring rebuilt from this answer, and "the row is
 * absent" (push one entry onto nothing) and "the row is damaged" (nine slots we cannot read)
 * are the same [] with opposite consequences. Keeping the distinction HERE rather than
 * re-spelling the decode at the call site is what keeps the two readers from drifting about
 * which values count as a ring.
 *
 * An absent row decodes to an EMPTY ring rather than to null: nothing was written yet, which
 * is a perfectly readable answer.
 *
 * @param mixed $raw  The stored meta value.
 * @return array|null  The raw (un-normalized) entry list, or null if the value is not a ring.
 */
function _pp_decode_history_ring($raw): ?array {
    if ($raw === '' || $raw === null || $raw === false) {
        return [];
    }
    $entries = is_array($raw) ? $raw : json_decode((string) $raw, true);
    return (is_array($entries) && pp_is_list($entries)) ? $entries : null;
}


/**
 * Converts normalized ring entries back into their STORED form (#818).
 *
 * The inverse of pp_get_composition_history()'s normalization, and mandatory before any
 * write of `_pp_composition_history`: a raw entry's decoded bytes must go back to base64
 * before the ring meets a JSON encoder, or invalid UTF-8 either fails the encode of the
 * whole ring or gets silently substituted — see that function's docblock for which
 * encoder does which. Composition entries pass through untouched.
 *
 * The append path in pp_update_composition() reads the ring through the normalizer and
 * appends in normalized form, so this is the SINGLE place that knows the on-disk
 * encoding. Keep it that way: an entry persisted around this converter is unreadable to
 * pp_get_composition_history() and vanishes on the next write.
 *
 * @param array $entries  Normalized entries, from either ring reader — the write path feeds
 *                        it the authoritative one (#823); see pp_get_composition_history()
 *                        for the shape.
 * @return array  The same list in stored form.
 */
function _pp_history_entries_for_storage(array $entries): array {
    return array_map(function (array $entry): array {
        if (!pp_history_entry_is_raw($entry)) {
            return $entry;
        }
        $encoded = base64_encode($entry['raw']);
        unset($entry['raw']);
        return $entry + ['raw_b64' => $encoded];
    }, $entries);
}

/**
 * Reads pp_token_overrides authoritatively inside the lock, distinguishing an ABSENT
 * row from an UNREADABLE (corrupt/truncated/non-array) one. Reads the single option row
 * straight from the DB (bypassing the options cache) so a concurrent writer's
 * just-committed value is visible and a stale cached map can't overwrite a newer one
 * inside the critical section. Reads exactly the one row rather than busting the whole
 * `alloptions` autoload cache.
 *
 * Four distinct outcomes (#207 + #212):
 *   - read FAILED         → null  (DB error on the SELECT — #212, fail closed)
 *   - no row              → []    (legitimately no overrides)
 *   - row unserializes to an array → that array
 *   - row exists but does NOT unserialize to an array → null (UNREADABLE)
 *
 * #212: $wpdb->get_var() returns null in TWO distinct cases — the query ran and matched
 * no rows (a genuinely absent row → correct []), AND the query FAILED (DB error, killed
 * connection mid-statement). Treating a read failure as "no overrides exist" would record
 * [] as the run's rollback baseline, and a later `apply restore` would DELETE every touched
 * token (the unset() branch of pp_revert_tokens) — the exact silent loss #200/#207 close.
 * Disambiguate via $wpdb->last_error: wpdb::query() flushes it to '' at the start of every
 * query and sets it on error, so after the option SELECT a non-empty last_error means the
 * read failed → fail closed (null). This check must precede the ($raw === null) branch: a
 * failed read also returns null, and must not be mistaken for a genuinely absent row.
 *
 * Only the snapshot caller (pp_snapshot_token_overrides) needs the unreadable-vs-empty
 * distinction, so it reads through this strict variant. The writer paths read through
 * _pp_read_token_overrides_locked(), which coerces null to [] — see that wrapper.
 *
 * @param object|null $wpdb  The DB handle inside the lock, or null in unit context.
 * @return array|null  The overrides map, [] if absent, or null if the read failed or the
 *                     row is unreadable.
 */
function _pp_read_token_overrides_locked_strict($wpdb = null): ?array {
    if (is_object($wpdb) && method_exists($wpdb, 'get_var')) {
        $raw = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
                'pp_token_overrides'
            )
        );
        // #212: a read failure (non-empty last_error) is NOT an absent row — fail closed.
        // Checked before ($raw === null) because a failed get_var() also yields null.
        if (! empty($wpdb->last_error)) {
            return null;
        }
        if ($raw === null) {
            return [];
        }
        $value = maybe_unserialize($raw);
        return is_array($value) ? $value : null;
    }
    // No DB handle (unit context): fall back to the cached/stubbed option.
    return pp_get_token_overrides();
}

/**
 * Reads pp_token_overrides authoritatively inside the lock for the WRITER paths, where
 * `[]`-means-"start fresh" is the correct handling of both an absent AND an unreadable
 * row. Thin wrapper over _pp_read_token_overrides_locked_strict() that coerces the
 * strict variant's null (unreadable row) back to []. Keeping this coercion here — not in
 * the strict read — is the Option A boundary from #207: only the rollback-baseline
 * snapshot treats an unreadable row as a hard failure; set/clear/clear-all/revert keep
 * their pre-#207 "start fresh on a missing/unreadable row" semantics unchanged.
 *
 * @param object|null $wpdb  The DB handle inside the lock, or null in unit context.
 */
function _pp_read_token_overrides_locked($wpdb = null): array {
    $value = _pp_read_token_overrides_locked_strict($wpdb);
    return $value === null ? [] : $value;
}

/**
 * Sets a single design token override in the database.
 *
 * @param string $token  CSS custom property name (e.g. '--color-accent').
 * @param string $value  The override value.
 * @return bool  True on success; false if the write failed or the lock was not acquired.
 */
function pp_set_token_override(string $token, string $value): bool {
    return _pp_with_token_lock(function ($wpdb) use ($token, $value) {
        $overrides = _pp_read_token_overrides_locked($wpdb);
        $overrides[$token] = $value;
        $result = update_option('pp_token_overrides', $overrides, true);
        pp_invalidate_design_tokens_cache();
        return $result;
    }, false);
}

/**
 * Clears a single design token override, reverting it to the product default.
 *
 * @param string $token  CSS custom property name.
 * @return bool  True if the token was present and removed; false if absent or unlocked.
 */
function pp_clear_token_override(string $token): bool {
    return _pp_with_token_lock(function ($wpdb) use ($token) {
        $overrides = _pp_read_token_overrides_locked($wpdb);
        if (!array_key_exists($token, $overrides)) {
            return false;
        }
        unset($overrides[$token]);
        if (empty($overrides)) {
            delete_option('pp_token_overrides');
        } else {
            update_option('pp_token_overrides', $overrides, true);
        }
        pp_invalidate_design_tokens_cache();
        return true;
    }, false);
}

/**
 * Clears all design token overrides, reverting the entire site to product defaults.
 *
 * @return int  Number of overrides that were cleared (0 if none or lock not acquired).
 */
function pp_clear_all_token_overrides(): int {
    return _pp_with_token_lock(function ($wpdb) {
        $overrides = _pp_read_token_overrides_locked($wpdb);
        $count = count($overrides);
        if ($count > 0) {
            delete_option('pp_token_overrides');
            pp_invalidate_design_tokens_cache();
        }
        return $count;
    }, 0);
}

/**
 * Reverts a scoped set of token overrides to the values held in a frozen snapshot.
 *
 * This is the rollback primitive behind `wp pp apply restore`. Unlike the whole-map
 * writers above, it touches ONLY the keys in $touched_keys, so unrelated overrides
 * (including ones written by later runs) are preserved:
 *   - key present in $snapshot  → set the override to the snapshot value
 *   - key absent from $snapshot → clear the override (the run created it)
 *   - key not in $touched_keys  → left exactly as-is
 *
 * Runs inside the same advisory-lock critical section as the other writers, doing one
 * read-modify-write of pp_token_overrides so the revert is atomic against concurrent
 * applies. Fail-closed: every scoped snapshot value is validated against the live token
 * registry BEFORE any write, and a single invalid entry (corrupt/hand-edited snapshot
 * file) aborts the entire revert with NO mutation — never a partial restore.
 *
 * @param array $snapshot      token => value map captured pre-apply (the prior state).
 * @param array $touched_keys  the keys this run wrote (primary + derived); the revert scope.
 * @return bool  True on a confirmed write; false if the lock was not acquired OR a scoped
 *               snapshot entry was invalid (in which case nothing is mutated).
 */
function pp_revert_tokens(array $snapshot, array $touched_keys): bool {
    return _pp_with_token_lock(function ($wpdb) use ($snapshot, $touched_keys) {
        $registry = pp_design_tokens();

        // Pre-validate the whole scope BEFORE touching anything. Any scoped key that is
        // present in the snapshot must be a registered token with a value valid for its
        // type. One bad entry aborts with no write — fail-closed, no partial mutation.
        foreach ($touched_keys as $key) {
            if (!is_string($key) || !array_key_exists($key, $snapshot)) {
                continue;
            }
            $value = $snapshot[$key];
            if (!is_string($value) || !array_key_exists($key, $registry)
                || _pp_validate_token_value($value, $registry[$key]['type'] ?? null) !== true) {
                return false;
            }
        }

        $overrides = _pp_read_token_overrides_locked($wpdb);
        foreach ($touched_keys as $key) {
            if (!is_string($key)) {
                continue;
            }
            if (array_key_exists($key, $snapshot)) {
                $overrides[$key] = $snapshot[$key];
            } else {
                // The run created this override; rolling back removes it.
                unset($overrides[$key]);
            }
        }

        if (empty($overrides)) {
            delete_option('pp_token_overrides');
        } else {
            update_option('pp_token_overrides', $overrides, true);
        }
        pp_invalidate_design_tokens_cache();
        return true;
    }, false);
}

/**
 * Reads the current token overrides under the advisory lock, for snapshotting.
 *
 * Used when freezing a run's pre-apply baseline: taking the read inside the lock means
 * a concurrent apply cannot interleave and produce a baseline that never existed
 * atomically. Fail-closed on TWO distinct failure modes, both surfaced as null:
 *   1. Lock not acquired (#200): the read would be non-atomic exactly when contention
 *      is happening — the one scenario the lock exists to protect against — so it
 *      returns null instead of silently degrading to a plain cached read.
 *   2. Unreadable row (#207): a corrupt/truncated/hand-edited pp_token_overrides row
 *      that does not unserialize to an array. Reading through the STRICT locked read
 *      surfaces this as null rather than coercing it to [] — recording [] as the
 *      baseline would let a later `apply restore` delete the touched tokens instead of
 *      restoring them (an [] baseline reverts every touched key via the unset() branch
 *      of pp_revert_tokens).
 * The caller must treat null as a hard failure (no baseline recorded) rather than
 * freezing a snapshot that a later `apply restore` would roll back to. An absent row
 * still yields [] (a valid, recordable empty baseline), never null.
 *
 * @return array|null  token => value map, [] if no overrides, or null if the lock could
 *                     not be acquired OR the overrides row is unreadable.
 */
function pp_snapshot_token_overrides(): ?array {
    return _pp_with_token_lock( function ( $wpdb ) {
        return _pp_read_token_overrides_locked_strict( $wpdb );
    }, null );
}

// ── Token Family Derivation ─────────────────────────────────────────────────
// When a base token changes, derived tokens must update to stay visually coherent.

/**
 * Parses a hex color string to [r, g, b] (0-255 each).
 *
 * @param string $hex  e.g. '#7a4f2e' or '7a4f2e'.
 * @return array{int, int, int}|null  [r, g, b] or null if unparseable.
 */
function _pp_hex_to_rgb(string $hex): ?array {
    $hex = ltrim($hex, '#');
    if (strlen($hex) === 3) {
        $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
    }
    if (strlen($hex) !== 6 || !ctype_xdigit($hex)) {
        return null;
    }
    return [
        (int) hexdec(substr($hex, 0, 2)),
        (int) hexdec(substr($hex, 2, 2)),
        (int) hexdec(substr($hex, 4, 2)),
    ];
}

/**
 * Converts [r, g, b] (0-255) to a hex color string.
 */
function _pp_rgb_to_hex(int $r, int $g, int $b): string {
    return sprintf('#%02x%02x%02x', max(0, min(255, $r)), max(0, min(255, $g)), max(0, min(255, $b)));
}

/**
 * Mixes a color with another at a given ratio (0.0 = all $base, 1.0 = all $mix).
 *
 * @param array{int,int,int} $base  RGB of the base color.
 * @param array{int,int,int} $mix   RGB of the mix color.
 * @param float              $ratio 0.0–1.0 blend factor toward $mix.
 * @return string  Hex color.
 */
function _pp_color_mix(array $base, array $mix, float $ratio): string {
    $r = (int) round($base[0] + ($mix[0] - $base[0]) * $ratio);
    $g = (int) round($base[1] + ($mix[1] - $base[1]) * $ratio);
    $b = (int) round($base[2] + ($mix[2] - $base[2]) * $ratio);
    return _pp_rgb_to_hex($r, $g, $b);
}

/**
 * Token family definitions: base token → derived tokens with mix ratios.
 *
 * Each derived token is produced by mixing the base color with black or white.
 * Ratios are calibrated against the product defaults in base.css so the visual
 * relationships hold regardless of the chosen accent/text hue.
 *
 * @return array<string, array<string, array{mix: 'black'|'white', ratio: float}>>
 */
function pp_token_families(): array {
    return [
        '--color-accent' => [
            '--color-accent-hover'   => ['mix' => 'black', 'ratio' => 0.15],
            '--color-accent-strong'  => ['mix' => 'black', 'ratio' => 0.30],
            '--color-border-accent'  => ['mix' => 'white', 'ratio' => 0.55],
            '--color-surface-accent' => ['mix' => 'white', 'ratio' => 0.88],
            // On-inverted accent roles (#437): the light-surface accent fails AA on
            // the dark inverted band (3.23:1), so links there route through a
            // lightened accent tint instead. Derived by mixing accent toward white
            // so a retheme's new accent auto-produces a matching on-inverted tint;
            // registered here so a pinned override that DIVERGES surfaces in the
            // #386 stale-warning / masked-derived machinery like any other derived.
            '--color-accent-on-inverted'       => ['mix' => 'white', 'ratio' => 0.55],
            '--color-accent-on-inverted-hover' => ['mix' => 'white', 'ratio' => 0.70],
            // On-overlay accent roles (#461): links/numbers on a bg-image band sit on a
            // dark rgba(0,0,0,.55) overlay over an ARBITRARY image. The worst case is the
            // overlay over pure white (rgb(115,115,115)), where the contrast ceiling for
            // ANY foreground is 4.74:1 — so the default is mechanically near-white to clear
            // AA (4.5:1). Derived by mixing accent almost fully toward white so a retheme's
            // new accent still resolves to a near-white on-overlay tint; registered here so
            // a pinned override that DIVERGES surfaces in the #386 machinery like on-inverted.
            '--color-accent-on-overlay'        => ['mix' => 'white', 'ratio' => 0.976],
            '--color-accent-on-overlay-hover'  => ['mix' => 'white', 'ratio' => 1.0],
        ],
        '--color-text' => [
            '--color-text-secondary' => ['mix' => 'white', 'ratio' => 0.20],
        ],
    ];
}

/**
 * Derives related tokens from a base token value.
 *
 * @param string $base_token  e.g. '--color-accent'.
 * @param string $base_value  Hex color value for the base token.
 * @return array<string, string>  Derived token name => hex value. Empty if not a family base or not a valid hex.
 */
function pp_derive_family_tokens(string $base_token, string $base_value): array {
    $families = pp_token_families();
    if (!isset($families[$base_token])) {
        return [];
    }

    $rgb = _pp_hex_to_rgb($base_value);
    if ($rgb === null) {
        return [];
    }

    $black = [0, 0, 0];
    $white = [255, 255, 255];
    $derived = [];

    foreach ($families[$base_token] as $derived_token => $recipe) {
        $mix_color = $recipe['mix'] === 'black' ? $black : $white;
        $derived[$derived_token] = _pp_color_mix($rgb, $mix_color, $recipe['ratio']);
    }

    return $derived;
}

/**
 * Reports whether two color values resolve to the same RGB triplet.
 *
 * Normalizes hex shorthand/case (#FFF == #ffffff), so a derived override written
 * by a prior auto-derivation compares equal to the value recomputed now. Any value
 * that is not parseable hex (e.g. an rgba()/var() pin) is treated as NOT equivalent
 * to the always-hex derived value — an intentional non-hex pin diverges from the
 * base derivation and is therefore surfaced as masking.
 *
 * @param string $a
 * @param string $b
 * @return bool
 */
function _pp_colors_equivalent(string $a, string $b): bool {
    if ($a === $b) {
        return true;
    }
    $ra = _pp_hex_to_rgb($a);
    $rb = _pp_hex_to_rgb($b);
    if ($ra === null || $rb === null) {
        return false;
    }
    return $ra === $rb;
}

/**
 * Detects existing derived-family overrides that MASK a base token change (#386).
 *
 * `update_design_token` auto-derives family tokens that have no override but
 * PRESERVES existing overrides (deliberately pinned derived values must survive).
 * A preserved override that no longer matches what the current/new base would
 * derive keeps winning in the rendered CSS, so the base change "succeeds"
 * (ok:true) yet has no visible effect where that override applies. This is the
 * masking condition: a derived override EXISTS *and* DIVERGES from the value the
 * base would derive. A coherent override (equal to the derivable value) is not
 * masking and is not reported; mere presence is not staleness.
 *
 * One shared engine, two surfaces: the apply result (`stale_warnings`) and the
 * INSPECT smell (`pp_detect_masked_derived_smells()`) both call this.
 *
 * @param string $base_token  e.g. '--color-accent'.
 * @param string $base_value  Current/new value for the base token.
 * @return array<array{token: string, current: string, expected: string, message: string}>
 *         One entry per masking derived override. Empty when the token is not a
 *         family base, the base value is not a resolvable hex, or every existing
 *         derived override is coherent with the base.
 */
function pp_masked_derived_overrides(string $base_token, string $base_value): array {
    // pp_derive_family_tokens() returns [] for non-family bases and for base
    // values that aren't resolvable hex (var()/rgba()) — no derivation, so no
    // divergence can be computed and nothing is masked.
    $derived = pp_derive_family_tokens($base_token, $base_value);
    if (empty($derived)) {
        return [];
    }

    $overrides = pp_get_token_overrides();
    $masking = [];

    foreach ($derived as $derived_token => $derived_value) {
        // No override → the token is (or will be) auto-derived from the base, so
        // it can't mask the base change. A non-string override value can only come
        // from a corrupt pp_token_overrides option (the write path enforces string);
        // skip it rather than let this advisory detector fatal on bad stored data —
        // this runs on the read-only INSPECT surface, which must stay chaos-tolerant.
        if (!isset($overrides[$derived_token]) || !is_string($overrides[$derived_token])) {
            continue;
        }
        // Coherent override → matches what the base derives; not masking.
        if (_pp_colors_equivalent($overrides[$derived_token], $derived_value)) {
            continue;
        }

        $masking[] = [
            'token'    => $derived_token,
            'current'  => $overrides[$derived_token],
            'expected' => $derived_value,
            'message'  => sprintf(
                '%s (%s) is a derived override present and unchanged; it diverges from the %s (%s) derivation (%s), so the base change may not be visible where it applies.',
                $derived_token, $overrides[$derived_token], $base_token, $base_value, $derived_value
            ),
        ];
    }

    return $masking;
}

/**
 * Site-level INSPECT smell for masked derived-family overrides (#386).
 *
 * Walks every token family over its CURRENT effective base value and reports the
 * same masking condition as `pp_masked_derived_overrides()`, so an operator sees
 * the base/derived incoherence at INSPECT — before a base change, not only after
 * one at APPLY. Quiet on a coherently themed site (auto-derived families match
 * their base); fires on genuine incoherence (e.g. a blue accent base with an
 * orange accent-strong override left from a previous palette).
 *
 * @return array<array{type: string, base_token: string, token: string, current: string, expected: string, message: string}>
 */
function pp_detect_masked_derived_smells(): array {
    $tokens = pp_design_tokens();
    $smells = [];

    foreach (array_keys(pp_token_families()) as $base_token) {
        if (!isset($tokens[$base_token])) {
            continue;
        }
        // Guard against a corrupt token record whose value isn't a string before
        // handing it to pp_masked_derived_overrides()'s string parameter — INSPECT
        // must not fatal on bad stored option data.
        $base_value = $tokens[$base_token]['value'] ?? null;
        if (!is_string($base_value)) {
            continue;
        }
        foreach (pp_masked_derived_overrides($base_token, $base_value) as $m) {
            $smells[] = [
                'type'       => 'masked_derived_override',
                'base_token' => $base_token,
                'token'      => $m['token'],
                'current'    => $m['current'],
                'expected'   => $m['expected'],
                'message'    => $m['message'],
            ];
        }
    }

    return $smells;
}

/**
 * Returns custom font URLs from the database.
 *
 * @return array  Array of URL strings.
 */
function pp_get_font_urls(): array {
    $urls = get_option('pp_font_urls', []);
    return is_array($urls) ? $urls : [];
}

/**
 * Sets the custom font URLs in the database.
 *
 * @param array $urls  Array of URL strings.
 * @return bool  True on success.
 */
function pp_set_font_urls(array $urls): bool {
    return update_option('pp_font_urls', array_values($urls), true);
}

/**
 * Single source of truth for the site-option whitelist (a security boundary —
 * the only WP options the AI/CLI surface may read or write). Maps each allowed
 * key to its value type, used for server-side validation on write.
 *
 * Types: 'string' (free text) | 'attachment_id' (a positive int that resolves
 * to a Media Library attachment — never a raw URL) | 'bool' (a canonical
 * on/off flag: accepts 1/0/true/false, stored as '1' or '0') | 'color' (a CSS
 * color accepted by the shared _pp_validate_color() engine — hex/rgb/hsl,
 * transparent/currentColor, or a single known color-typed design-token
 * reference) | 'gradient' (the shared color-OR-gradient union: everything
 * 'color' accepts, PLUS a bounded linear-gradient()/radial-gradient() with 2+
 * color stops — used for the chrome BACKGROUND options, issue 333).
 * '0' (not '') is the canonical OFF form for bool so a stored
 * value always re-validates — the snapshot/rollback path re-applies it
 * through the validating writer.
 *
 * @return array<string,string>  key => type
 */
function pp_allowed_site_options(): array {
    return [
        'blogname'             => 'string',
        'blogdescription'      => 'string',
        'pp_logo_id'           => 'attachment_id',
        'pp_logo_alt'          => 'string',
        // Browser-tab favicon + app/OS icon (issue 414). This is WordPress
        // core's own `site_icon` option (the value the Customizer's Site Icon
        // control writes), not a pp_ option, so once it is set core's
        // wp_site_icon() hook emits the `<link rel="icon">` / apple-touch-icon
        // tags into wp_head() automatically — the theme adds no rendering code.
        // It is the SAME shape of change as pp_logo_id (a whitelisted option
        // pointing at a Media Library image attachment) and is validated by the
        // SAME pp_is_image_attachment rule, so the favicon is settable through
        // the same typed, validated path as the logo. No square/size constraint
        // is imposed here (a hard size reject would be a surface-specific rule the
        // logo keys don't have). Note: the Customizer's square-crop + subsize step
        // does NOT run on a direct option write, so core's wp_site_icon() renders
        // the chosen attachment as-is — a square source (ideally >=512px) is what
        // looks right across the icon sizes. Like every attachment_id key, empty/0
        // is rejected rather than treated as an unset.
        'site_icon'            => 'attachment_id',
        // Site-option surface for the footer logo. The footer is template-owned
        // chrome (issue 223), so it cannot be composed to pass show_logo; this
        // option is the only supported way to turn the footer logo on (issue 234).
        'pp_footer_show_logo'  => 'bool',
        // Dark-marketing-footer chrome (issue 300). The footer is template-owned
        // (issue 223) and not a composition component, so it has no style_slots;
        // these site options are the supported surface. Colors emit inline
        // --footer-* custom properties; strings render brand/contact/copyright.
        //
        // pp_footer_bg is 'gradient', not 'color' (issue 333). The 'gradient' type
        // is a color-OR-gradient UNION (see _pp_validate_token_value()), so it is a
        // strict superset of 'color': every value that validated before still does.
        // Issue 300 typed it 'color' on the belief that the color engine already
        // accepted gradients; it does not (_pp_validate_color() has no gradient
        // branch), so a gradient footer was silently inexpressible. Widened here
        // alongside the header rather than left as an asymmetry the AI would have
        // to memorize ("header takes a gradient, footer does not").
        'pp_footer_bg'         => 'gradient',
        'pp_footer_text'       => 'color',
        'pp_footer_link_color' => 'color',
        'pp_footer_blurb'      => 'string',
        'pp_footer_contact'    => 'string',
        'pp_footer_copyright'  => 'string',
        // Footer STRUCTURE (issue 335). Issue 300 gave the footer a dark tone
        // but no organisation; these add the generic layout affordances a
        // marketing footer needs — labelled columns and a delimited bottom bar
        // — as more of the SAME site-option surface (still no footer builder,
        // still template-owned chrome, issue 223). All optional; empty = today's
        // rendering, so an unset footer stays byte-identical.
        //   - menu/contact labels: optional column headings above the footer
        //     nav menu and the contact block (empty = unlabelled, as before).
        //   - note: optional secondary line. When NON-EMPTY it moves the
        //     copyright into a delimited bottom bar and renders alongside it;
        //     empty leaves the copyright inline exactly as issue 300 did.
        'pp_footer_menu_label'    => 'string',
        'pp_footer_contact_label' => 'string',
        // Optional heading above the SECOND footer menu column (issue 469).
        // Generic name — the "Legal" column is one use of the second location
        // (footer_secondary), not the capability itself. Empty = a headless
        // second column (the same headless-when-unset rule as pp_footer_menu_label).
        'pp_footer_secondary_label' => 'string',
        'pp_footer_note'          => 'string',
        // Footer logo OVERRIDE (issue 335). pp_logo_id feeds both the (light)
        // header and the (dark) footer, so a dark-on-transparent brand mark is
        // correct in the header and invisible on a dark footer. This optional
        // override lets the footer use a light logo variant while pp_logo_id
        // stays the header logo. Attachment ID (never a URL), validated by the
        // SAME pp_is_image_attachment rule as pp_logo_id. Unset falls back to
        // pp_logo_id via pp_resolve_logo's existing resolution chain.
        'pp_footer_logo_id'       => 'attachment_id',
        // Footer SOCIAL-ICON row (issue 382). A list-valued option: an ordered set
        // of {network, url} entries from a CLOSED set of known networks (see
        // pp_footer_social_networks()), rendered by the footer template as accessible
        // inline-SVG icon links in the reserved .site-footer__social slot (#427). The
        // value is a JSON string because a site option stores a single scalar; the
        // 'social' type is the only structured (non-scalar-semantics) option, so its
        // validator (below) is the one place that decodes + shape-checks it. Unset/''
        // = no row (byte-identical footer). External profile URLs, so URL validation
        // is http(s)-only (NOT the same-site redirect rule). No arbitrary icon URLs
        // or icon fonts: the network set is fixed and its glyphs ship inline.
        'pp_footer_social'        => 'social',
        // Header chrome (issue 333). The header/nav is template-owned (issue 223)
        // exactly like the footer, so it declares no style_slots and these site
        // options are its ONLY styling surface. Before this, the header was the one
        // above-the-fold element with no authorable surface at all: .site-header was
        // hard-bound to --color-bg. Colors emit inline --header-* custom properties.
        // pp_header_bg is 'gradient' (color OR gradient) so a gradient marketing
        // header is expressible; text/link stay 'color'.
        'pp_header_bg'         => 'gradient',
        'pp_header_text'       => 'color',
        'pp_header_link_color' => 'color',
        // Open Graph / Twitter social-share defaults (issue 468). The theme
        // emits NO og:*/twitter:* tags without these — sharing a page produced
        // no rich card. Site-level defaults for the whole install; per-page
        // overrides (og_title/twitter_title) live on _pp_seo_meta via
        // update_seo_meta. Rendered by pp_social_meta_tags() in wp_head.
        //   - pp_og_image: the social-share image. An image attachment ID (NOT
        //     a URL), validated by the SAME pp_is_image_attachment rule as
        //     pp_logo_id / site_icon — the established typed image surface. Feeds
        //     og:image (+ width/height from attachment metadata, alt from the
        //     attachment alt) and twitter:image. Unset = those tags are omitted.
        //   - pp_og_site_name: overrides the og:site_name default
        //     (get_bloginfo('name')). Free text, same as the other string
        //     options (no length cap — consistent with pp_footer_blurb et al.).
        //   - pp_og_default_description: the site-wide fallback social
        //     description used when a page has no meta_description. Capped at the
        //     SAME 320 chars as meta_description (see the key-scoped check in
        //     pp_validate_site_option_value) so the two description surfaces
        //     can't diverge on length.
        //   - pp_twitter_card: the Twitter card type. A CLOSED enum
        //     (summary | summary_large_image) — its own 'twitter_card' type so
        //     the accepted set lives in exactly one place (PP_TWITTER_CARD_VALUES),
        //     the same "dedicated validator for a structured/closed option" shape
        //     as pp_footer_social. Unset renders the summary_large_image default.
        'pp_og_image'               => 'attachment_id',
        'pp_og_site_name'           => 'string',
        'pp_og_default_description' => 'string',
        'pp_twitter_card'           => 'twitter_card',
    ];
}

/**
 * Upper bound on the length of pp_og_default_description, matching
 * meta_description's cap in _pp_validate_seo_meta() (issue 468). The site-wide
 * social description falls back INTO the same og:description slot a page's
 * meta_description fills, so a single shared cap keeps the two from diverging.
 */
const PP_OG_DESCRIPTION_MAX = 320;

/**
 * The CLOSED set of accepted pp_twitter_card values (issue 468), lower-cased.
 * `summary_large_image` is the default when the option is unset (see
 * pp_social_meta_tags()); `summary` is the compact card. A strict allowlist so a
 * typo is rejected rather than silently emitted into <head>.
 */
const PP_TWITTER_CARD_VALUES = ['summary', 'summary_large_image'];

/**
 * Canonical string forms accepted for a 'bool' site option, lower-cased.
 * A strict allowlist (not "any non-empty string is true") so a typo like
 * "flase" is rejected instead of silently coercing to on.
 */
const PP_BOOL_OPTION_TRUE  = ['1', 'true'];
const PP_BOOL_OPTION_FALSE = ['0', 'false'];

/**
 * Upper bound on the number of entries a pp_footer_social value may carry.
 * A footer social row is a short, curated set (a marketing footer shows a
 * handful of profiles), so a generous cap keeps the stored option and the
 * rendered row bounded without constraining any real use. Duplicates are
 * allowed (they simply render in order), so the cap is not tied to the size
 * of the network set.
 */
const PP_FOOTER_SOCIAL_MAX = 12;

/** Max accepted length for a single social profile URL (defensive bound). */
const PP_FOOTER_SOCIAL_URL_MAXLEN = 2048;

/**
 * The CLOSED set of social networks the footer can surface (issue 382), and the
 * single source of truth shared by BOTH the pp_footer_social validator (which
 * network keys are accepted) and the footer template (which glyph + accessible
 * label each renders). Keeping validation and rendering keyed off ONE map means
 * an accepted network can never be un-renderable, and adding a network later is
 * one additive entry here — no second list to keep in sync.
 *
 * Each glyph is a minimal, hand-authored single `<path>` on a 24x24 viewBox
 * (fill-rule:evenodd handles the marks that need a cut-out). No icon font and no
 * third-party icon library is vendored, and nothing is fetched at render time —
 * the SVG ships inline with the theme. `label` is the accessible name used for
 * the link's aria-label; `path` is the glyph's `d` attribute.
 *
 * @return array<string, array{label:string, path:string}>
 */
function pp_footer_social_networks(): array {
    return [
        'x' => [
            'label' => 'X',
            'path'  => 'M18.244 2.25h3.308l-7.227 8.26 8.502 11.24h-6.657l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z',
        ],
        'linkedin' => [
            'label' => 'LinkedIn',
            'path'  => 'M20.447 20.452h-3.554v-5.569c0-1.328-.027-3.037-1.852-3.037-1.853 0-2.136 1.445-2.136 2.939v5.667H9.351V9h3.414v1.561h.046c.477-.9 1.637-1.85 3.37-1.85 3.601 0 4.267 2.37 4.267 5.455v6.286zM5.337 7.433a2.062 2.062 0 01-2.063-2.065 2.064 2.064 0 112.063 2.065zm1.782 13.019H3.555V9h3.564v11.452zM22.225 0H1.771C.792 0 0 .774 0 1.729v20.542C0 23.227.792 24 1.771 24h20.451C23.2 24 24 23.227 24 22.271V1.729C24 .774 23.2 0 22.222 0h.003z',
        ],
        'facebook' => [
            'label' => 'Facebook',
            'path'  => 'M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z',
        ],
        'instagram' => [
            'label' => 'Instagram',
            'path'  => 'M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zM12 0C8.741 0 8.333.014 7.053.072 2.695.272.273 2.69.073 7.052.014 8.333 0 8.741 0 12c0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98C8.333 23.986 8.741 24 12 24c3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98C15.668.014 15.259 0 12 0zm0 5.838a6.162 6.162 0 100 12.324 6.162 6.162 0 000-12.324zM12 16a4 4 0 110-8 4 4 0 010 8zm6.406-11.845a1.44 1.44 0 100 2.881 1.44 1.44 0 000-2.881z',
        ],
        'youtube' => [
            'label' => 'YouTube',
            'path'  => 'M23.498 6.186a3.016 3.016 0 00-2.122-2.136C19.505 3.545 12 3.545 12 3.545s-7.505 0-9.377.505A3.017 3.017 0 00.502 6.186C0 8.07 0 12 0 12s0 3.93.502 5.814a3.016 3.016 0 002.122 2.136c1.871.505 9.376.505 9.376.505s7.505 0 9.377-.505a3.015 3.015 0 002.122-2.136C24 15.93 24 12 24 12s0-3.93-.502-5.814zM9.545 15.568V8.432L15.818 12l-6.273 3.568z',
        ],
        'github' => [
            'label' => 'GitHub',
            'path'  => 'M12 .297c-6.63 0-12 5.373-12 12 0 5.303 3.438 9.8 8.205 11.385.6.113.82-.258.82-.577 0-.285-.01-1.04-.015-2.04-3.338.724-4.042-1.61-4.042-1.61C4.422 18.07 3.633 17.7 3.633 17.7c-1.087-.744.084-.729.084-.729 1.205.084 1.838 1.236 1.838 1.236 1.07 1.835 2.809 1.305 3.495.998.108-.776.417-1.305.76-1.605-2.665-.3-5.466-1.332-5.466-5.93 0-1.31.465-2.38 1.235-3.22-.135-.303-.54-1.523.105-3.176 0 0 1.005-.322 3.3 1.23.96-.267 1.98-.399 3-.405 1.02.006 2.04.138 3 .405 2.28-1.552 3.285-1.23 3.285-1.23.645 1.653.24 2.873.12 3.176.765.84 1.23 1.91 1.23 3.22 0 4.61-2.805 5.625-5.475 5.92.42.36.81 1.096.81 2.22 0 1.606-.015 2.896-.015 3.286 0 .315.21.69.825.57C20.565 22.092 24 17.592 24 12.297c0-6.627-5.373-12-12-12z',
        ],
        'tiktok' => [
            'label' => 'TikTok',
            'path'  => 'M12.525.02c1.31-.02 2.61-.01 3.91-.02.08 1.53.63 3.09 1.75 4.17 1.12 1.11 2.7 1.62 4.24 1.79v4.03c-1.44-.05-2.89-.35-4.2-.97-.57-.26-1.1-.59-1.62-.93-.01 2.92.01 5.84-.02 8.75-.08 1.4-.54 2.79-1.35 3.94-1.31 1.92-3.58 3.17-5.91 3.21-1.43.08-2.86-.31-4.08-1.03-2.02-1.19-3.44-3.37-3.65-5.71-.02-.5-.03-1-.01-1.49.18-1.9 1.12-3.72 2.58-4.96 1.66-1.44 3.98-2.13 6.15-1.72.02 1.48-.04 2.96-.04 4.44-.99-.32-2.15-.23-3.02.37-.63.41-1.11 1.04-1.36 1.75-.21.51-.15 1.07-.14 1.61.24 1.64 1.82 3.02 3.5 2.87 1.12-.01 2.19-.66 2.77-1.61.19-.33.4-.67.41-1.06.1-1.79.06-3.57.07-5.36.01-4.03-.01-8.05.02-12.07z',
        ],
        'mastodon' => [
            'label' => 'Mastodon',
            'path'  => 'M23.268 5.313c-.35-2.578-2.617-4.61-5.304-5.004C17.51.242 15.792 0 11.813 0h-.03c-3.98 0-4.835.242-5.288.309C3.882.692 1.496 2.518.917 5.127.64 6.412.61 7.837.661 9.143c.074 1.874.088 3.745.26 5.611.118 1.24.325 2.47.62 3.68.55 2.237 2.777 4.098 4.96 4.857 2.336.792 4.849.923 7.256.38.265-.061.527-.132.786-.213.585-.184 1.27-.39 1.774-.753a.057.057 0 00.023-.043v-1.809a.052.052 0 00-.02-.041.053.053 0 00-.046-.01 20.282 20.282 0 01-4.709.545c-2.73 0-3.463-1.284-3.674-1.818a5.593 5.593 0 01-.319-1.433.053.053 0 01.066-.054c1.517.363 3.072.546 4.632.546.376 0 .75 0 1.125-.01 1.57-.044 3.224-.124 4.768-.422.038-.008.077-.015.11-.024 2.435-.464 4.753-1.92 4.989-5.604.008-.145.03-1.52.03-1.67.002-.512.167-3.63-.024-5.545zm-3.748 9.195h-2.561V8.29c0-1.309-.55-1.976-1.67-1.976-1.23 0-1.846.79-1.846 2.35v3.403h-2.546V8.663c0-1.56-.617-2.35-1.848-2.35-1.112 0-1.668.668-1.67 1.977v6.218H4.822V8.102c0-1.31.337-2.35 1.011-3.12.696-.77 1.608-1.164 2.74-1.164 1.311 0 2.302.5 2.962 1.498l.638 1.06.638-1.06c.66-.999 1.65-1.498 2.96-1.498 1.13 0 2.043.395 2.74 1.164.675.77 1.012 1.81 1.012 3.12z',
        ],
    ];
}

/**
 * Validates a pp_footer_social value (issue 382). The value is a JSON string:
 * an ordered, non-empty list of {network, url} objects. This is the single place
 * the structured shape is decoded and checked, so an accepted value is always
 * renderable by the footer template.
 *
 * Rules (all must hold, else a descriptive WP_Error):
 *   - decodes to a JSON array that is a sequential LIST (a JSON object, which
 *     json_decode turns into an associative PHP array, is rejected);
 *   - non-empty and at most PP_FOOTER_SOCIAL_MAX entries;
 *   - each entry is a JSON object (associative array) with STRING `network` and
 *     `url` members;
 *   - `network` is a key in the closed pp_footer_social_networks() set;
 *   - `url` (trimmed) is <= PP_FOOTER_SOCIAL_URL_MAXLEN, is a valid absolute URL
 *     (filter_var), and its scheme is http or https. These are EXTERNAL profile
 *     URLs, so the same-site redirect validator is deliberately NOT reused; the
 *     scheme allowlist blocks javascript:/data:/protocol-relative values.
 *
 * The empty string is handled by the caller (it means "clear"), not here.
 *
 * @param string $value  Raw JSON string.
 * @return true|WP_Error
 */
function _pp_validate_footer_social(string $value) {
    $err = static function (string $msg) {
        return new WP_Error('invalid_option_value', 'Option "pp_footer_social" ' . $msg);
    };

    $decoded = json_decode($value, true);
    if (!is_array($decoded) || !pp_is_list($decoded)) {
        return $err('must be a JSON array of {network, url} entries.');
    }
    if ($decoded === []) {
        return $err('must contain at least one {network, url} entry (use "" to clear).');
    }
    if (count($decoded) > PP_FOOTER_SOCIAL_MAX) {
        return $err(sprintf('may contain at most %d entries.', PP_FOOTER_SOCIAL_MAX));
    }

    $networks = pp_footer_social_networks();
    foreach ($decoded as $entry) {
        // A JSON object decodes to a non-list associative array. A list-shaped
        // child (e.g. ["x","https://..."]) is not the {network,url} object shape.
        if (!is_array($entry) || pp_is_list($entry)) {
            return $err('entries must each be a {network, url} object.');
        }
        if (!isset($entry['network'], $entry['url'])
            || !is_string($entry['network']) || !is_string($entry['url'])) {
            return $err('each entry needs a string "network" and a string "url".');
        }
        if (!isset($networks[$entry['network']])) {
            return $err(sprintf(
                'has unknown network "%s". Allowed: %s.',
                $entry['network'], implode(', ', array_keys($networks))
            ));
        }
        $url = trim($entry['url']);
        // Reject control characters and raw HTML/attribute-breaking characters up
        // front (a legitimate URL percent-encodes them). filter_var is lenient
        // about these, so this guard keeps the STORED value clean; esc_url is still
        // the production escaping boundary at render (defense in depth).
        if ($url === '' || strlen($url) > PP_FOOTER_SOCIAL_URL_MAXLEN
            || preg_match('/[\x00-\x20<>"\'`]/', $url) === 1
            || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return $err(sprintf('has an invalid URL for "%s".', $entry['network']));
        }
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if ($scheme !== 'http' && $scheme !== 'https') {
            return $err(sprintf('URL for "%s" must be an http(s) URL.', $entry['network']));
        }
    }
    return true;
}

/**
 * Canonicalizes a validated pp_footer_social value for storage: decode, keep
 * ONLY {network, url} (url trimmed) per entry in original order, re-encode.
 * Drops any extra keys and normalizes whitespace so a stored value survives a
 * round-trip through the validating writer (the snapshot/rollback path). The
 * caller has already validated $value, so decoding cannot fail here.
 *
 * @param string $value  A value already accepted by _pp_validate_footer_social.
 * @return string        Canonical JSON, or '' if (defensively) re-encoding fails.
 */
function pp_normalize_footer_social(string $value): string {
    $decoded = json_decode($value, true);
    $canonical = [];
    foreach ((array) $decoded as $entry) {
        $canonical[] = [
            'network' => (string) $entry['network'],
            'url'     => trim((string) $entry['url']),
        ];
    }
    $json = wp_json_encode($canonical, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return is_string($json) ? $json : '';
}

/**
 * Single source of truth for "is $id a Media Library image attachment?".
 *
 * Every trust boundary that accepts an attachment ID meant to render as an
 * image (the `pp_logo_id` site option, component `logo_id` props, the logo
 * resolver) funnels through this predicate so the definition of "valid image
 * attachment" can never drift between them. A non-image, trashed, non-existent,
 * or non-attachment ID all return false. SVGs are excluded because WP core's
 * wp_attachment_is_image() rejects them (see AiContextTest).
 *
 * @param int $id  Attachment post ID.
 * @return bool    True only for a live attachment whose type is a displayable image.
 */
function pp_is_image_attachment(int $id): bool {
    return $id > 0
        && get_post_type($id) === 'attachment'
        && wp_attachment_is_image($id);
}

/**
 * Validates a site-option value against its declared type.
 *
 * @param string $key    Whitelisted option key (caller has already checked membership).
 * @param string $value  Proposed value.
 * @return true|WP_Error
 */
function pp_validate_site_option_value(string $key, string $value) {
    $type = pp_allowed_site_options()[$key] ?? null;
    if ($type === 'attachment_id') {
        if (!pp_is_image_attachment((int) $value)) {
            return new WP_Error('invalid_option_value', sprintf(
                'Option "%s" requires a Media Library image attachment ID, got "%s".',
                $key, $value
            ));
        }
    }
    if ($type === 'bool') {
        $norm = strtolower(trim($value));
        if (!in_array($norm, PP_BOOL_OPTION_TRUE, true) && !in_array($norm, PP_BOOL_OPTION_FALSE, true)) {
            return new WP_Error('invalid_option_value', sprintf(
                'Option "%s" requires a boolean (1/0/true/false), got "%s".',
                $key, $value
            ));
        }
    }
    if ($type === 'color') {
        // Delegate to the shared color engine (issue 230) — the SAME validator
        // every style-slot color goes through. No second, surface-specific color
        // rule (a repo invariant): a footer color and a component color slot can
        // never drift apart in what they accept.
        if (!_pp_validate_color($value)) {
            return new WP_Error('invalid_option_value', sprintf(
                'Option "%s" requires a CSS color (hex, rgb()/hsl(), transparent, '
                . 'currentColor, or a known color token reference), got "%s".',
                $key, $value
            ));
        }
    }
    if ($type === 'gradient') {
        // Delegate to the shared slot-type engine (issue 333) — the SAME validator
        // every `gradient`-typed style slot goes through, for the same reason the
        // 'color' branch above delegates: no second, surface-specific rule. The
        // 'gradient' type is a color-OR-gradient union, so a background option
        // accepts every plain color a 'color' option does, PLUS a bounded
        // linear-gradient()/radial-gradient(). Routed through
        // _pp_validate_token_value() (not a bare _pp_validate_gradient() call) so
        // the union stays defined in exactly one place.
        if (_pp_validate_token_value($value, 'gradient') !== true) {
            return new WP_Error('invalid_option_value', sprintf(
                'Option "%s" requires a CSS color (hex, rgb()/hsl(), transparent, '
                . 'currentColor, or a known color token reference) or a bounded '
                . 'linear-gradient()/radial-gradient() with 2+ color stops, got "%s".',
                $key, $value
            ));
        }
    }
    if ($type === 'social') {
        // Footer social-icon row (issue 382). '' is a valid CLEAR (no row); any
        // other value is a JSON list of {network, url} entries decoded and
        // shape-checked by the dedicated validator (the only structured option
        // type — see _pp_validate_footer_social for the full contract).
        if (trim($value) === '') {
            return true;
        }
        return _pp_validate_footer_social($value);
    }
    if ($type === 'twitter_card') {
        // og/twitter card type (issue 468). '' is a valid CLEAR (renderer uses
        // the summary_large_image default); any other value must be in the
        // CLOSED PP_TWITTER_CARD_VALUES set so a typo can't reach <head>.
        if (trim($value) === '') {
            return true;
        }
        if (!in_array(strtolower(trim($value)), PP_TWITTER_CARD_VALUES, true)) {
            return new WP_Error('invalid_option_value', sprintf(
                'Option "%s" must be one of: %s (or empty to clear), got "%s".',
                $key, implode(', ', PP_TWITTER_CARD_VALUES), $value
            ));
        }
    }
    // Key-scoped length cap for the site-wide social description (issue 468).
    // Not a type rule: pp_og_default_description is a plain 'string' option (so
    // it round-trips like the other text options), but it feeds the SAME
    // og:description slot as a page's meta_description, so it shares that
    // surface's 320-char cap rather than inventing a second limit.
    if ($key === 'pp_og_default_description' && strlen($value) > PP_OG_DESCRIPTION_MAX) {
        return new WP_Error('invalid_option_value', sprintf(
            'Option "%s" must be %d characters or fewer.', $key, PP_OG_DESCRIPTION_MAX
        ));
    }
    return true;
}

/**
 * Normalizes a validated 'bool' site-option value to its canonical stored form:
 * '1' (on) or '0' (off). Caller must have validated the value first. Both forms
 * are themselves valid bool inputs, so a stored value survives a round-trip
 * through pp_update_site_option (as the snapshot/rollback path requires).
 *
 * @param string $value  A value already accepted by pp_validate_site_option_value.
 * @return string        '1' or '0'.
 */
function pp_normalize_bool_option(string $value): string {
    return in_array(strtolower(trim($value)), PP_BOOL_OPTION_TRUE, true) ? '1' : '0';
}

/**
 * Returns a whitelisted WordPress option value.
 * Whitelist is the single source pp_allowed_site_options().
 *
 * @param string $key  Option name (must be whitelisted).
 * @return string|WP_Error  Option value, or WP_Error if key not whitelisted.
 */
function pp_site_option(string $key) {
    if (!isset(pp_allowed_site_options()[$key])) {
        return new WP_Error('invalid_option', sprintf('Option "%s" is not whitelisted.', $key));
    }
    return (string) get_option($key, '');
}

/**
 * Resolves the site logo for a nav/footer component into a render-ready shape.
 * Attachment-ID only — never accepts a raw URL as input. The returned `url` is
 * an OUTPUT, resolved from the attachment ID for the <img src>.
 *
 * Resolution order: explicit `logo_id` prop → `pp_logo_id` site option (the
 * AI/CLI safe surface) → WP `custom_logo` theme-mod → text wordmark.
 *
 * Alt resolution: explicit `logo_alt` → the attachment's own alt metadata →
 * the wordmark text. (In nav/footer the logo replaces the text wordmark, so a
 * meaningful alt is correct; an empty decorative alt would only apply if a
 * layout rendered both the image AND the visible site title together.)
 *
 * `logo_alt` reaches this resolver from the `pp_logo_alt` SITE OPTION, which
 * templates/base.php maps onto both chrome components' `logo_alt` prop (#582).
 * It is not authored per page: nav and footer are template-owned chrome (#223)
 * and a composition naming either is rejected outright. Before #582 nothing
 * passed `logo_alt` at all, so the whitelisted option had no consumer anywhere
 * and a write to it succeeded while changing nothing rendered.
 *
 * EMPTY IS ABSENT, on BOTH branches. base.php passes '' for an unset option, so
 * the resolver must treat '' exactly like an omitted prop or every site on earth
 * would get an empty alt the moment the option was wired. The image branch has
 * always normalized with `=== ''`; the text branch used `??` alone, which was
 * unreachable while nothing passed the prop and became reachable with #582. Both
 * branches now fall back, which is what keeps the guarantee below true.
 *
 * WHITESPACE-ONLY COUNTS AS UNPROVIDED (#582, maintainer-ruled). The emptiness
 * test is `trim(...) === ''`, not `=== ''`. `pp_logo_alt` is a 'string' option and
 * _pp_validate_site_option_value has no 'string' branch, so a value reaches here
 * exactly as written — and `"   "` is not `''`. Without the trim it counted as an
 * authored alt: the logo rendered `alt="   "`, which announces nothing to a screen
 * reader AND suppressed the attachment's own alt metadata, leaving the operator
 * strictly worse off than not setting the option at all. That is the
 * reported-success-without-effect class this gate exists to remove, so a
 * whitespace-only value now falls through exactly like an unset one.
 *
 * The trim decides only WHETHER a value counts as provided. It never rewrites one:
 * the stored option is untouched and a real value renders verbatim, surrounding
 * spaces included. Fixing this at the write instead was rejected — validation lives
 * in the shared engines and this repo does not add surface-specific validators.
 *
 * `alt` is NEVER empty in the returned shape, for any site that has a title.
 * That is a contract of this function, not an accident of its callers: nav.php
 * and footer.php only read `alt` on the image branch today, but a consumer that
 * reads it on the text branch must not be handed ''. The stated precondition is
 * the honest one — the chain's terminal hop is pp_site_title(), so a site whose
 * title is itself empty is the one case that bottoms out at ''. That is not a
 * regression this function can close; it is what "the site has no name" means.
 *
 * `logo_text` is normalized the same empty-is-absent way, whitespace included, for
 * the same reason and so the guarantee cannot be defeated from the far end (it is
 * the chain's last hop before the site title). That also makes the long-standing
 * schema claim "Falls back to pp_site_title() when empty" true — before #582 it
 * fell back only when the key was ABSENT.
 *
 * @param array $props  Component props (logo_id, logo_alt, logo_text).
 * @return array{type:string,url:string,alt:string,text:string}
 *         type is 'image' when an attachment resolved to a URL, else 'text'.
 */
function pp_resolve_logo(array $props): array {
    // Empty-is-absent, whitespace included — same rule as logo_alt below (#582).
    // The value is used VERBATIM when it counts; trim() only decides whether it does.
    $raw_text = (string) ($props['logo_text'] ?? '');
    $text     = trim($raw_text) !== '' ? $raw_text : pp_site_title();

    $id = 0;
    if (!empty($props['logo_id'])) {
        $id = (int) $props['logo_id'];
    } elseif (($opt = get_option('pp_logo_id', '')) !== '') {
        $id = (int) $opt;
    } elseif (($mod = get_theme_mod('custom_logo')) !== false && $mod) {
        $id = (int) $mod;
    }

    // Explicit image guard: only resolve an <img> for a real image attachment.
    // Previously this relied on wp_get_attachment_image_url() incidentally
    // returning false for non-images (#155). Making the check explicit means a
    // non-image ID (from any source above) deterministically falls through to
    // the text wordmark instead of depending on WP core internals.
    if (pp_is_image_attachment($id)) {
        $url = wp_get_attachment_image_url($id, 'full');
        if ($url) {
            // trim() decides whether an alt was PROVIDED; the value itself is used
            // verbatim. A whitespace-only pp_logo_alt counts as unprovided, so the
            // attachment's own alt still wins instead of being suppressed (#582).
            $alt = (string) ($props['logo_alt'] ?? '');
            if (trim($alt) === '') {
                $meta_alt = (string) get_post_meta($id, '_wp_attachment_image_alt', true);
                $alt = trim($meta_alt) !== '' ? $meta_alt : $text;
            }
            return ['type' => 'image', 'url' => $url, 'alt' => $alt, 'text' => $text];
        }
    }

    // Same empty-is-absent normalization as the image branch above (#582). There is
    // no attachment on this branch, so the chain is one hop: explicit alt, else the
    // wordmark text.
    $text_alt = (string) ($props['logo_alt'] ?? '');
    return [
        'type' => 'text',
        'url'  => '',
        'alt'  => trim($text_alt) !== '' ? $text_alt : $text,
        'text' => $text,
    ];
}

// ── Site-state write functions (persistence wrappers) ────────────────────────

/**
 * Generates a component id for entries written without an authored `id` prop.
 *
 * The `pp-<hex8>` shape is RESERVED for generated ids: pp_is_generated_component_id()
 * (lib/guardrails.php) pattern-matches this exact format to tell generated ids from
 * authored ones, and tests pin the two together — change one, change both. A new
 * random id is produced on every full-composition write for id-less entries (#232),
 * so these ids are NOT durable across a declarative re-apply.
 *
 * @return string  Generated id in the reserved `pp-<hex8>` format.
 */
function pp_generate_component_id(): string {
    return 'pp-' . bin2hex(random_bytes(4)); // 4 bytes → exactly 8 hex chars
}

/**
 * Writes a composition array to post meta, bumping the freshness marker (#113), with
 * optional write-time compare-and-swap on the version (#13).
 *
 * Thin persistence wrapper — handles JSON serialization internally.
 * Does NOT validate (the action layer owns validation).
 *
 * The composition write and the marker bump (_pp_composition_version +
 * _pp_composition_hash) happen under a per-post advisory lock so concurrent writers to
 * the same post can't interleave and lose a version bump. Lock-acquire failure returns a
 * WP_Error and writes NOTHING — never a silent non-atomic write (the #200 lesson). The
 * content hash is computed on the canonical PRE-id-injection form (see
 * pp_composition_content_hash) so the id injection below can't false-conflict the marker.
 *
 * Optimistic locking (#13): when $expected_version is non-null, the fresh in-lock version
 * read must equal it or the write is rejected with a `composition_conflict` WP_Error and
 * NOTHING is written — neither the composition nor either marker moves. The check happens
 * AFTER the advisory lock is held and against the same fresh-from-DB read that computes the
 * next version, so it is atomic: an interleaved external write that lands between a caller's
 * read and this write bumps the version and is caught here, not just at a pre-check (closing
 * the TOCTOU gap #113's preflight gate left open). The comparison is on the version integer,
 * which the stable-id injection below never changes, so an id round-trip can't false-conflict.
 * A null $expected_version skips the CAS entirely (documented back-compat: legacy callers,
 * new-page creation, and the homepage seed all write unconditionally). An absent marker reads
 * as version 0, so a legacy/never-written page accepts $expected_version === 0 and initializes
 * to version 1.
 *
 * IN-LOCK PRECONDITION (#829), the fourth parameter and the newest of the three gates this
 * critical section applies. The CAS above answers "did the page move since you read it?";
 * this answers whatever question only the caller can ask, and it must be asked from INSIDE
 * the lock or the answer is a guess. It exists because `restore_composition` resolves
 * `steps_back` / `history_index` against a ring read taken BEFORE the lock: the writer
 * cannot know what a selector means, and the action cannot open this lock, so the seam is
 * a callable the action supplies and this function evaluates in the one place where the
 * ring cannot move. See _pp_restore_target_still_addressed() (lib/actions.php).
 *
 * THE CONTRACT ON THAT CALLABLE, stated because a hook inside a critical section is a
 * footgun if it is left to taste:
 *   - It is evaluated AFTER the CAS and BEFORE any write. The CAS block reads the version
 *     and returns; it writes nothing. So a refusal here leaves the composition, both
 *     markers and the history ring exactly as they were, exactly as a CAS rejection does.
 *   - It RECEIVES the authoritative in-lock history ring, already read and normalized, so it
 *     never has to issue its own read — the rebuild below reuses the same array, one read per
 *     lock hold. Take the ring as a parameter; do not re-read it.
 *   - It returns true to proceed, or a WP_Error to refuse. The WP_Error is returned to the
 *     caller verbatim — this function does not re-wrap or re-code it, because the caller is
 *     the only party that knows what its own precondition means.
 *   - It is READ-ONLY. It must not write meta, mutate globals, or perform work proportional
 *     to anything: every microsecond it takes is a microsecond this post's write lock is
 *     held against every other writer.
 *   - It MUST NOT THROW. The declared return is true|WP_Error, and an exception is neither:
 *     it propagates out of this function to the caller, which is expecting a value. The lock
 *     itself is safe either way (_pp_with_advisory_lock releases in a `finally`), so this is
 *     a contract rule rather than a leak.
 *   - It MUST NOT RE-ENTER this writer, or otherwise take this post's composition lock. It
 *     already runs inside that lock.
 *   - There is no third state, and it FAILS CLOSED. Only a literal true proceeds; a WP_Error
 *     refuses with that error; anything else refuses with `composition_precondition_invalid`,
 *     because a falsy non-WP_Error returned from here would read as SUCCESS to every caller's
 *     is_wp_error() check, over a write that never happened.
 * Null (the default) skips the gate entirely — and skips the ring read with it, so a caller
 * that supplies no precondition pays nothing at all.
 *
 * @param int           $post_id           WordPress post ID.
 * @param array         $composition       Array of component objects.
 * @param int|null      $expected_version  The version the caller based its edit on, or null to
 *                                         skip the compare-and-swap.
 * @param callable|null $in_lock_precondition  Optional read-only gate evaluated inside the
 *                                         lock, after the CAS and before any write. Receives
 *                                         the authoritative in-lock history ring (array).
 *                                         Returns true to proceed or a WP_Error to refuse.
 * @return true|WP_Error  true on write; WP_Error('composition_conflict') on a version
 *                        mismatch; the precondition's own WP_Error when it refuses;
 *                        WP_Error('composition_lock_failed') on lock-acquire failure.
 */
function pp_update_composition(int $post_id, array $composition, ?int $expected_version = null, ?callable $in_lock_precondition = null) {
    // Hash the canonical PRE-stable-id form, before the id-injection loop below mutates
    // the array. (pp_composition_content_hash also strips ids defensively, so the two are
    // belt-and-suspenders — the hash is stable across the id round-trip either way.)
    $hash = pp_composition_content_hash($composition);

    // Auto-assign generated IDs to entries that don't have one. The id persists in
    // props, so it survives in-place actions (update/insert/remove/reorder) that
    // round-trip the stored array — but a full-array rewrite (update_composition /
    // create_page from a source JSON without ids) regenerates it (#232). Only an
    // explicit authored `id` is durable across declarative re-apply; validators
    // detect the generated shape via pp_is_generated_component_id().
    //
    // COUPLING (issue 147): because this injects props['id'] into EVERY component,
    // every composable component's schema.json MUST declare an `id` prop — otherwise
    // the injected id would be rejected as an unknown prop key on the next validated
    // write. The invariant is guarded by
    // SchemaValidationTest::testEveryComposableComponentDeclaresIdSoInjectedIdNeverFalseRejects.
    foreach ($composition as &$item) {
        $props = $item['props'] ?? [];
        if (empty($props['id'])) {
            $item['props']['id'] = pp_generate_component_id();
        }
    }
    unset($item);

    $json = wp_json_encode($composition, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    return _pp_with_composition_lock($post_id, function ($wpdb) use ($post_id, $json, $hash, $expected_version, $in_lock_precondition) {
        // Read the version fresh from the DB inside the lock (bypassing the meta cache the
        // pre-lock freshness check may have warmed). Absent → 0, so the first write is v1.
        $current_version = _pp_read_composition_version_locked($wpdb, $post_id);

        // Write-time compare-and-swap (#13). Only when the caller supplied a baseline: reject
        // if the target moved since. This runs under the same lock and against the same fresh
        // read as the bump below, so the check-and-set is atomic — no window for an external
        // write to slip between them. Nothing has been written yet, so a mismatch leaves the
        // composition and both markers exactly as they were.
        if ($expected_version !== null && $current_version !== $expected_version) {
            return new WP_Error(
                'composition_conflict',
                'The composition for post ' . $post_id . ' changed since you last read it '
                . '(expected version ' . $expected_version . ', current version ' . $current_version . '). '
                . 'Another writer (a CLI action, the dashboard editor, or the AI chat) modified it. '
                . 'Re-read the current composition and re-apply your change. [composition_conflict]'
            );
        }

        // The caller's own in-lock precondition (#829). Placed HERE — after the CAS, before
        // the ring read and every write below — for three reasons, all of them load-bearing:
        //
        //   NOTHING HAS BEEN WRITTEN YET. The only statement above it inside this lock is a
        //   version READ, so a refusal here is exactly as clean as a CAS rejection: the
        //   composition, both markers and the ring are untouched, and $composition itself is
        //   this function's own by-value copy, so even the caller's array is unmutated.
        //
        //   THE CAS ANSWERS FIRST. A stale baseline means "the page moved under you", which
        //   subsumes every narrower precondition and is the error the caller already knows
        //   how to retry. Running a precondition ahead of it would report the narrower cause
        //   for a wider failure.
        //
        //   IT IS INSIDE THE LOCK. That is the whole point (#823's lesson applied to a
        //   caller's own question rather than to this writer's ring read): a precondition
        //   evaluated before the lock is a statement about a moment that has already passed.
        //
        // ONE AUTHORITATIVE RING READ PER LOCK HOLD, shared with the rebuild below. The
        // precondition needs the ring and so does the rebuild, and nothing between them can
        // change it — this lock is what stops that. Reading twice would decode the whole row
        // twice inside the critical section, which at the composition size ceiling is tens of
        // milliseconds of json_decode that every concurrent writer to this post waits for.
        // Sharing also makes the two consumers see the SAME ring structurally, rather than two
        // reads that merely cannot disagree, and lets a degraded read log once instead of twice.
        //
        // Read HERE and not at the top of the mutator: the rebuild's read is conditional on a
        // prior composition existing, so hoisting it unconditionally would add a query to every
        // first write and every create_page. Null precondition, null cost.
        //
        // Returned verbatim. See this function's docblock for the callable's full contract.
        $locked_history = null;
        if ($in_lock_precondition !== null) {
            $locked_history = _pp_read_composition_history_locked($post_id);
            $precondition   = $in_lock_precondition($locked_history);
            // STRICTLY true TO PROCEED, and the strictness is the #200 lesson rather than
            // fussiness. `is_wp_error($precondition)` alone would let a precondition that
            // returned null or false fall through to `return $precondition` below, handing
            // _pp_with_composition_lock() a falsy NON-WP_Error — which every caller's
            // `if (is_wp_error($result))` reads as SUCCESS, over a write that never happened.
            // That is precisely the ok:true-over-a-non-write shape #829 exists to close, so
            // this gate must not be able to manufacture it. A contract violation fails CLOSED.
            if ($precondition !== true) {
                return is_wp_error($precondition) ? $precondition : new WP_Error(
                    'composition_precondition_invalid',
                    'The in-lock precondition for post ' . $post_id . ' returned '
                    . gettype($precondition) . ' rather than true or a WP_Error; the write was '
                    . 'skipped because a precondition that cannot be read cannot be trusted. '
                    . 'This is a caller bug. [composition_precondition_invalid]'
                );
            }
        }

        $next_version = $current_version + 1;

        // History ring (#133): push the PRIOR composition onto the bounded per-post
        // history meta BEFORE overwriting, so the state this write replaces stays
        // restorable. Runs inside this same advisory lock as the marker bump, so the
        // ring never interleaves with a concurrent write to the same post. Only when a
        // prior composition exists — a brand-new page's first write has nothing to
        // preserve (and pushing an empty baseline would waste a ring slot). Read the
        // prior JSON straight from the DB (bypassing a possibly-stale meta cache) so the
        // captured bytes are exactly what a later restore replays.
        $prior_json = _pp_read_composition_json_locked($wpdb, $post_id);
        if ($prior_json !== null) {
            // THE DECODE DECIDES THE ENTRY SHAPE, NOT WHETHER TO PUSH (#818). This used to
            // be `if (is_array($prior_items)) { push }` — so a page whose stored bytes did
            // not decode to an array got NO entry, and the update_post_meta() three lines
            // below destroyed the only recoverable copy of those bytes. That is the exact
            // state the rest of the pipeline treats as precious: #144 classifies it, #725
            // makes the read path say "treat as corrupted, not empty", #749 refuses a batch
            // rather than roll a snapshot over it, #748 stopped six action surfaces from
            // telling an agent to populate over it — and the DOCUMENTED repair for it is
            // one full update_composition write, i.e. this function. The recovery path was
            // the destructive one.
            //
            // Three classifications reach here and none of them is a composition:
            // `decode_error` (undecodable), the valid-JSON-SCALAR sub-case of
            // `unexpected_shape` (`null`, `5`, `"text"`), and — since #841 — the JSON
            // OBJECT sub-case of `unexpected_shape` (`{"component":…}`, `{"a":…,"b":…}` —
            // but NOT `{"0":…,"1":…}`, which json_decode hands back as a genuine list and
            // which nothing below the decode can distinguish from an authored array).
            //
            // THE OBJECT CASE IS WHY THIS TEST IS pp_is_list() AND NOT is_array() (#841).
            // json_decode(…, true) hands back a PHP ASSOCIATIVE array for a JSON object,
            // so bare is_array() called it a composition and filed it as a replayable
            // snapshot: `wp pp operate composition-history` reported it restorable with a
            // component COUNT taken off the object's keys, and selecting it drove the
            // id-injection loop above onto a string band — an uncaught TypeError on both
            // the CLI action surface and the chat batch executor, reached by following the
            // repair route the theme's own corruption message recommends. The composition
            // contract is a LIST (pp_validate_composition_errors() refuses a non-list
            // container, #724), so the push has to ask the contract's own question. Asking
            // the decode rather than re-running pp_get_composition_result() still covers
            // all three with one branch, and is still the only honest test available inside
            // the lock: the classifier reads through the meta CACHE, while these are the
            // authoritative bytes read from the DB.
            //
            // Rings written before #841 already hold mis-filed object entries;
            // _pp_normalize_history_ring() reclassifies those on the way out, so the
            // read side and this write side agree on the same list test.
            //
            // A raw entry is a PRESERVED-BYTES record, not a snapshot. It is not a
            // composition and no reader may treat it as one — see pp_get_composition_history()
            // for the two entry forms and pp_history_entry_is_raw() for the predicate.
            // THE MARKER IS READ FROM THE ROW, NOT THE CACHE (#828), and this was the last
            // of the four in-lock reads to be. It has to be read HERE — before the
            // update_post_meta() calls below overwrite `_pp_composition_hash` with the
            // marker of the state being written — or the entry would record the state it
            // preserves as having the hash of the state that replaced it.
            $prior_items = json_decode($prior_json, true);
            $entry       = [
                'timestamp' => time(),
                'version'   => $current_version,
                'hash'      => _pp_read_composition_hash_locked($post_id),
            ];
            $entry += _pp_history_payload_is_snapshot($prior_items)
                ? ['composition' => $prior_items]
                : ['raw' => $prior_json];

            // Assembled in NORMALIZED form and converted to the stored form ONCE, on the
            // way out. Building the new entry pre-encoded would put the on-disk encoding
            // in two places 1200 lines apart, and would make "every ring write goes
            // through the converter" a request in a docblock rather than a fact about
            // the code. It is the fact that matters: an entry persisted without the
            // conversion is unreadable to pp_get_composition_history(), which is a silent
            // corruption path in the one subsystem whose whole job is losing nothing.
            //
            // READ THE RING FROM THE DATABASE, not the cache (#823). This is a
            // read-modify-write inside the lock, so the READ has to be as authoritative as
            // the two above it: a request that warmed the post's meta cache before blocking
            // on GET_LOCK would otherwise rebuild the ring from its pre-lock copy and drop
            // whatever the writer it waited for had just pushed — including, since #818, a
            // preserved-bytes entry that is the only recoverable copy of a corrupt page.
            // Already read above when a precondition ran (#829); otherwise read now. Either
            // way it is ONE read of the authoritative row per lock hold. `[]` is a real ring
            // value, so the coalesce tests null and not emptiness.
            $history   = $locked_history ?? _pp_read_composition_history_locked($post_id);
            $history[] = $entry;
            $max = pp_composition_history_max();
            if (count($history) > $max) {
                $history = array_slice($history, -$max);
            }
            // A FAILED ENCODE MUST NOT BECOME A WIPED RING. base64 closes the invalid-UTF-8
            // failure class for the raw payload, but it is not the only one — JSON_ERROR_DEPTH
            // still reaches here, because wrapping a prior array inside an entry inside the
            // ring nests it two levels deeper than it sat when json_decode() accepted it. On
            // false, wp_slash(false) is false and update_post_meta would store an empty value
            // that reads back as [] — all ten slots gone, on the very write that was meant to
            // preserve one. Keeping the previous ring is strictly better than destroying it.
            //
            // This guard covers the CLOBBER half only. That a skipped push leaves the write
            // below it to proceed anyway — losing the prior state silently — is the older,
            // wider gap tracked in #821, whose fix is a posture decision (fail closed vs
            // report) rather than a line.
            $encoded = wp_json_encode(
                _pp_history_entries_for_storage($history),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
            if ($encoded !== false) {
                update_post_meta($post_id, '_pp_composition_history', wp_slash($encoded));
            } else {
                // A BREADCRUMB IS NOT A POSTURE. Which of "fail the write" / "report it on
                // the envelope" this should become is #821's call; that a skipped push
                // leaves no trace at all is not a decision anyone made. One line makes the
                // difference between a diagnosable event and an unexplained gap in a ring.
                error_log(
                    'PromptingPress: composition post ' . $post_id
                    . ' history ring NOT updated (JSON encode failed: ' . json_last_error_msg()
                    . '); the previous ring was left intact but the state this write replaced was not recorded.'
                );
            }
        }

        // Write the composition first, then the hash, then the version LAST. A concurrent
        // marker reader (the freshness check, which is NOT under this lock) therefore
        // either sees the fully-updated marker or the pre-write version — a torn read
        // (new version, stale hash) can only make the freshness check MISMATCH, which
        // fails closed (rejects), never a silent false pass.
        update_post_meta($post_id, '_pp_composition', wp_slash($json));
        update_post_meta($post_id, '_pp_composition_hash', $hash);
        update_post_meta($post_id, '_pp_composition_version', $next_version);
        return true;
    }, new WP_Error(
        'composition_lock_failed',
        'Could not acquire the composition write lock for post ' . $post_id
        . '; the write was skipped to avoid a lost update. Retry once contention clears.'
    ));
}

/**
 * Updates a page title.
 *
 * @param int    $post_id  WordPress post ID.
 * @param string $title    New page title.
 * @return true|WP_Error
 */
function pp_update_page_title(int $post_id, string $title) {
    $result = wp_update_post(['ID' => $post_id, 'post_title' => $title], true);
    if (is_wp_error($result)) {
        return $result;
    }
    return true;
}

/**
 * Updates a page's slug (post_name) / permalink (#134).
 *
 * WordPress's own wp_update_post() de-duplicates post_name internally
 * (suffixing -2, -3, ... on collision, via wp_unique_post_slug()) — this
 * reads back the actual resulting slug afterward rather than assuming the
 * requested one stuck, so a caller always learns the real URL.
 *
 * @param int    $post_id  WordPress post ID.
 * @param string $slug     Desired slug. Sanitized via sanitize_title().
 * @return string|WP_Error  The resulting (possibly de-duplicated) slug, or WP_Error.
 */
function pp_update_page_slug(int $post_id, string $slug) {
    $sanitized = sanitize_title($slug);
    if ($sanitized === '') {
        return new WP_Error('invalid_slug', 'Slug must not be empty after sanitization.');
    }

    $result = wp_update_post(['ID' => $post_id, 'post_name' => $sanitized], true);
    if (is_wp_error($result)) {
        return $result;
    }

    $post = get_post($post_id);
    return ($post->post_name ?? '') !== '' ? $post->post_name : $sanitized;
}

// ── Page-specific SEO metadata (#41) ─────────────────────────────────────────
// Storage: a single post meta key (_pp_seo_meta, JSON-encoded), the same
// "one structured meta key" pattern as _pp_composition — not scattered flat
// meta keys, and not folded into the composition array itself (SEO metadata
// is page-level, not a layout/content concern).

/**
 * Returns page-specific SEO metadata for a post.
 *
 * @param int $post_id  WordPress post ID.
 * @return array{meta_description: string, seo_title: string, canonical_url: string, og_title: string, twitter_title: string}
 */
function pp_get_seo_meta(int $post_id): array {
    // og_title / twitter_title (issue 468) are per-page social-title overrides.
    // They live here (not in a separate meta key) so they round-trip through the
    // SAME #471-fixed store as seo_title/meta_description and are captured/
    // restored by the batch snapshot for free (it snapshots pp_get_seo_meta()).
    $defaults = [
        'meta_description' => '',
        'seo_title'        => '',
        'canonical_url'    => '',
        'og_title'         => '',
        'twitter_title'    => '',
    ];
    $raw = get_post_meta($post_id, '_pp_seo_meta', true);
    if (!is_string($raw) || $raw === '') {
        return $defaults;
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return $defaults;
    }
    return array_merge($defaults, array_intersect_key($decoded, $defaults));
}

/**
 * Validates a (possibly partial) SEO meta patch. Shared by the update_seo_meta
 * action's validate step and pp_update_seo_meta() itself (defense in depth,
 * same pattern as other write paths in this file).
 *
 * @return true|WP_Error
 */
function _pp_validate_seo_meta(array $meta) {
    $allowed_keys = ['meta_description', 'seo_title', 'canonical_url', 'og_title', 'twitter_title'];
    $unknown = array_diff(array_keys($meta), $allowed_keys);
    if (!empty($unknown)) {
        return new WP_Error('invalid_key', 'Unknown SEO meta key(s): ' . implode(', ', $unknown) . '. Allowed: ' . implode(', ', $allowed_keys) . '.');
    }
    if (isset($meta['canonical_url']) && $meta['canonical_url'] !== '' && !filter_var($meta['canonical_url'], FILTER_VALIDATE_URL)) {
        return new WP_Error('invalid_canonical_url', 'canonical_url must be a valid URL, or an empty string to clear it.');
    }
    if (isset($meta['meta_description']) && strlen($meta['meta_description']) > 320) {
        return new WP_Error('meta_description_too_long', 'meta_description must be 320 characters or fewer.');
    }
    // og_title / twitter_title share seo_title's 200-char cap (issue 468) — they
    // are validated exactly like seo_title, the title surface they fall back to.
    foreach (['seo_title', 'og_title', 'twitter_title'] as $title_key) {
        if (isset($meta[$title_key]) && strlen($meta[$title_key]) > 200) {
            return new WP_Error($title_key . '_too_long', $title_key . ' must be 200 characters or fewer.');
        }
    }
    return true;
}

/**
 * Writes page-specific SEO metadata for a post. Shallow-merges into existing
 * values — unspecified keys are left unchanged (same patch semantics as
 * update_component's props). Pass an empty string to clear a field.
 *
 * @param int   $post_id  WordPress post ID.
 * @param array $meta     Subset of {meta_description, seo_title, canonical_url}.
 * @return true|WP_Error
 */
function pp_update_seo_meta(int $post_id, array $meta) {
    if (!get_post($post_id)) {
        return new WP_Error('invalid_post', 'Post not found.');
    }

    $valid = _pp_validate_seo_meta($meta);
    if (is_wp_error($valid)) {
        return $valid;
    }

    $updated = array_merge(pp_get_seo_meta($post_id), $meta);
    // JSON_UNESCAPED_UNICODE stores non-ASCII (accents, em-dash) as raw UTF-8
    // instead of \uXXXX escapes — without it, update_post_meta()'s unslash pass
    // stripped the backslash and turned "á" into a literal "u00e1" (#471).
    // wp_slash() then protects the escapes that are still present in any JSON
    // string (\" and \\) from that same unslash pass. Same store idiom as the
    // composition path (see the _pp_composition write in pp_apply_composition()).
    update_post_meta(
        $post_id,
        '_pp_seo_meta',
        wp_slash(wp_json_encode($updated, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))
    );
    return true;
}

/**
 * Outputs the page-specific meta description tag, if set for the current
 * page. Hooked to wp_head in functions.php.
 */
function pp_seo_meta_description_tag(): void {
    if (!is_singular()) {
        return;
    }
    $post_id = get_queried_object_id();
    if (!$post_id) {
        return;
    }
    $meta_description = pp_get_seo_meta($post_id)['meta_description'];
    if ($meta_description === '') {
        return;
    }
    echo '<meta name="description" content="' . esc_attr($meta_description) . '">' . "\n";
}

/**
 * Filters the assembled document <title> to the page-specific seo_title
 * override, if set. Hooked to the pre_get_document_title filter in
 * functions.php — returning a non-empty value here short-circuits
 * wp_get_document_title()'s own title-parts assembly entirely.
 */
function pp_seo_document_title_override(string $title): string {
    if (!is_singular()) {
        return $title;
    }
    $post_id = get_queried_object_id();
    if (!$post_id) {
        return $title;
    }
    $seo_title = pp_get_seo_meta($post_id)['seo_title'];
    return $seo_title !== '' ? $seo_title : $title;
}

/**
 * Filters the canonical URL to the page-specific canonical_url override, if
 * set. Hooked to the get_canonical_url filter in functions.php. Returns the
 * raw (already-validated) URL — WP core's own rel_canonical() escapes it
 * with esc_url() at output time, so this must not pre-escape (would double-
 * encode).
 *
 * @param string       $canonical_url  WordPress's own computed canonical URL.
 * @param WP_Post|null $post           The post being rendered.
 * @return string
 */
function pp_seo_canonical_url_override(string $canonical_url, $post): string {
    if (!$post) {
        return $canonical_url;
    }
    $override = pp_get_seo_meta($post->ID)['canonical_url'];
    return $override !== '' ? $override : $canonical_url;
}

// ── Open Graph / Twitter social-share meta (#468) ────────────────────────────
// Theme-owned wp_head emitter, beside the seo_title/meta_description/canonical
// rendering above. Resolves the site-level defaults (pp_og_* options) and the
// per-page overrides (og_title/twitter_title on _pp_seo_meta) into og:* and
// twitter:* tags. Two hard rules the whole surface obeys:
//   1. Emit a tag ONLY when its resolved value is non-empty (no empty-content
//      tags in <head>).
//   2. Every value is escaped at the sink — esc_url for URLs, esc_attr for
//      everything else — because every input is operator-settable and reaches
//      raw <head> output. A value that escapes to '' (e.g. esc_url() rejecting a
//      malformed URL) is treated as empty and dropped by rule 1.

/**
 * Emits one social meta tag, escaped, or nothing when the value is empty.
 *
 * Single sink for every og and twitter tag so the escape + no-empty-tag rules
 * live in exactly one place (a value that can't be escaped to a non-empty
 * string is never emitted).
 *
 * @param string $rel    The attribute name: 'property' (og:*) or 'name' (twitter:*).
 * @param string $key    The tag key, e.g. 'og:title'. A code constant, never user input.
 * @param string $value  The raw (unescaped) value.
 * @param bool   $is_url When true, escape with esc_url() instead of esc_attr().
 */
function _pp_emit_social_meta(string $rel, string $key, string $value, bool $is_url = false): void {
    // A whitespace-only value is treated as empty (the no-empty-tag rule): a
    // `content="   "` tag is as useless as an empty one.
    if (trim($value) === '') {
        return;
    }
    $escaped = $is_url ? esc_url($value) : esc_attr($value);
    if ($escaped === '') {
        return;
    }
    echo '<meta ' . $rel . '="' . $key . '" content="' . $escaped . '">' . "\n";
}

/**
 * Outputs the Open Graph + Twitter Card meta tags for the current page (#468).
 * Hooked to wp_head in functions.php. Singular pages only — the fallback chains
 * are page-scoped (post title, permalink), so on archive/search/home/404 there
 * is no single post to describe and the whole block is skipped.
 *
 * Fallback chains (page → site → WP):
 *   - og:title       = og_title → seo_title → post title
 *   - twitter:title  = twitter_title → (og:title chain)
 *   - og/twitter:description = meta_description → pp_og_default_description → omit
 *   - og/twitter:image       = pp_og_image → omit
 *   - og:site_name   = pp_og_site_name → get_bloginfo('name')
 *   - og:url = permalink; og:type = website; og:locale = get_locale();
 *     twitter:card = pp_twitter_card → summary_large_image
 */
function pp_social_meta_tags(): void {
    if (!is_singular()) {
        return;
    }
    $post_id = get_queried_object_id();
    if (!$post_id) {
        return;
    }
    $meta = pp_get_seo_meta($post_id);

    // Title chains. A whitespace-only value counts as unset so the chain still
    // falls through (and the no-empty-tag rule holds) rather than emitting blank.
    $og_title = $meta['og_title'];
    if (trim($og_title) === '') {
        $og_title = $meta['seo_title'];
    }
    if (trim($og_title) === '') {
        $og_title = (string) get_the_title($post_id);
    }
    $twitter_title = trim($meta['twitter_title']) !== '' ? $meta['twitter_title'] : $og_title;

    // Description chain: page meta_description → site default → omit.
    $description = $meta['meta_description'];
    if (trim($description) === '') {
        $description = (string) get_option('pp_og_default_description', '');
    }

    // og:site_name: option override → the WP site name.
    $site_name = (string) get_option('pp_og_site_name', '');
    if (trim($site_name) === '') {
        $site_name = (string) get_bloginfo('name');
    }

    // twitter:card: option → default. Re-validate the stored value against the
    // closed set (a value that predates a whitelist change can't leak).
    $card = strtolower((string) get_option('pp_twitter_card', ''));
    if (!in_array($card, PP_TWITTER_CARD_VALUES, true)) {
        $card = 'summary_large_image';
    }

    _pp_emit_social_meta('property', 'og:type', 'website');
    _pp_emit_social_meta('property', 'og:site_name', $site_name);
    _pp_emit_social_meta('property', 'og:locale', (string) get_locale());
    _pp_emit_social_meta('property', 'og:url', (string) get_permalink($post_id), true);
    _pp_emit_social_meta('property', 'og:title', $og_title);
    _pp_emit_social_meta('property', 'og:description', $description);

    // Image tags. Re-check the attachment at render time — an ID validated on
    // write can be deleted/trashed later, and a stale ID must omit the image
    // tags, not emit a broken URL.
    $image_id = (int) get_option('pp_og_image', '');
    if (pp_is_image_attachment($image_id)) {
        $image_url = wp_get_attachment_image_url($image_id, 'full');
        if ($image_url) {
            _pp_emit_social_meta('property', 'og:image', (string) $image_url, true);
            $image_meta = wp_get_attachment_metadata($image_id);
            if (is_array($image_meta)) {
                if (!empty($image_meta['width'])) {
                    _pp_emit_social_meta('property', 'og:image:width', (string) (int) $image_meta['width']);
                }
                if (!empty($image_meta['height'])) {
                    _pp_emit_social_meta('property', 'og:image:height', (string) (int) $image_meta['height']);
                }
            }
            // Attachment alt only — no title fallback (an empty alt omits the tag).
            $image_alt = (string) get_post_meta($image_id, '_wp_attachment_image_alt', true);
            _pp_emit_social_meta('property', 'og:image:alt', $image_alt);
            _pp_emit_social_meta('name', 'twitter:image', (string) $image_url, true);
        }
    }

    _pp_emit_social_meta('name', 'twitter:card', $card);
    _pp_emit_social_meta('name', 'twitter:title', $twitter_title);
    _pp_emit_social_meta('name', 'twitter:description', $description);
}

/**
 * Creates a new page with the Composition template.
 *
 * @param string $title   Page title.
 * @param string $status  Post status (default 'draft').
 * @param string $slug    Optional slug (#134). Sanitized via sanitize_title().
 *                        Omit to let WordPress derive one from the title, as before.
 * @return int|WP_Error   New post ID, or WP_Error on failure.
 */
function pp_create_page(string $title, string $status = 'draft', string $slug = '') {
    $args = [
        'post_type'   => 'page',
        'post_title'  => $title,
        'post_status' => $status,
    ];

    if ($slug !== '') {
        $sanitized = sanitize_title($slug);
        if ($sanitized === '') {
            return new WP_Error('invalid_slug', 'Slug must not be empty after sanitization.');
        }
        $args['post_name'] = $sanitized;
    }

    $post_id = wp_insert_post($args, true);

    if (is_wp_error($post_id)) {
        return $post_id;
    }

    update_post_meta($post_id, '_wp_page_template', 'composition.php');
    return $post_id;
}

/**
 * Publishes a page (sets post_status to 'publish').
 *
 * @param int $post_id  WordPress post ID.
 * @return true|WP_Error
 */
function pp_publish_page(int $post_id) {
    $result = wp_update_post(['ID' => $post_id, 'post_status' => 'publish'], true);
    if (is_wp_error($result)) {
        return $result;
    }
    return true;
}

/**
 * Promotes an 'auto-draft' page to a real 'draft' on first meaningful save
 * (#121). The post-new.php intercept creates pages as 'auto-draft' so an
 * unsaved visit doesn't leave a permanent, visible junk page — this is the
 * other half: once the author actually saves something, the page needs to
 * behave like a normal draft (visible in Pages, not core-GC'd). No-op for
 * any other status.
 *
 * @param int $post_id  WordPress post ID.
 */
function pp_promote_auto_draft(int $post_id): void {
    if (get_post_status($post_id) === 'auto-draft') {
        $result = wp_update_post(['ID' => $post_id, 'post_status' => 'draft'], true);
        // The composition/title save this follows has already succeeded and
        // written real data — failing the whole request over a status-only
        // follow-up write would be disproportionate. But silently swallowing
        // it would leave a page with real content hidden (and GC-eligible)
        // with zero signal, so log it (adversarial review finding).
        if (is_wp_error($result)) {
            error_log('PromptingPress: pp_promote_auto_draft failed for post ' . $post_id
                . ': ' . $result->get_error_message());
        }
    }
}

/**
 * Updates a whitelisted WordPress option.
 * Whitelist is the single source pp_allowed_site_options(); value is validated
 * against the key's declared type (e.g. pp_logo_id must be an attachment ID).
 *
 * @param string $key    Option name (must be whitelisted).
 * @param string $value  New option value.
 * @return true|WP_Error
 */
function pp_update_site_option(string $key, string $value) {
    if (!isset(pp_allowed_site_options()[$key])) {
        return new WP_Error('invalid_option', sprintf('Option "%s" is not whitelisted.', $key));
    }
    $valid = pp_validate_site_option_value($key, $value);
    if (is_wp_error($valid)) {
        return $valid;
    }
    // Normalize to each type's canonical stored form.
    $type = pp_allowed_site_options()[$key] ?? null;
    if ($type === 'attachment_id') {
        $value = (string) (int) $value;
    } elseif ($type === 'bool') {
        $value = pp_normalize_bool_option($value);
    } elseif ($type === 'social') {
        // '' clears the row; a non-empty value is canonicalized (strip extra
        // keys / whitespace) so it survives the snapshot/rollback round-trip.
        $value = trim($value) === '' ? '' : pp_normalize_footer_social($value);
    } elseif ($type === 'twitter_card') {
        // Store the lower-cased canonical form (or '' to clear) so a stored
        // value always re-validates through the snapshot/rollback path.
        $value = trim($value) === '' ? '' : strtolower(trim($value));
    }
    update_option($key, $value);
    return true;
}

// ── Front-end redirects (#62) ────────────────────────────────────────────────
// A generic, site-agnostic safe-surface redirect capability. Renamed/moved
// pages (see update_page_slug, #134) leave their old URL 404ing; a redirect
// records old-path → canonical-target so the old URL 301s instead. Storage is
// a single DB option (survives theme updates — never hardcoded in theme files).
//
// Store shape (option `pp_redirects`):
//   [ normalized_from_path => ['to' => string, 'code' => int(301|302)], ... ]
//
// Resolver runs only on an otherwise-unmatched (404) front-end request, so a
// redirect never shadows a live page and the lookup stays off the hot path for
// every normal hit. Open-redirect safety is layered: same-site validation at
// write time (_pp_validate_redirect_target) AND wp_safe_redirect()'s own host
// allowlist at resolve time.
//
//   create_redirect(from,to) ──▶ validate ──▶ pp_redirects option
//                                   │
//   GET /old  ─(404)─▶ template_redirect ─▶ pp_resolve_redirect('/old')
//                                   │                    │ match {to,code}
//                                   └────────────────────▶ wp_safe_redirect(to)
//
// Inside a CHAT BATCH both writers are undone by a rollback (#854). The batch
// snapshots the pp_redirects rows its steps NAME — per key, never the whole option,
// so a row written by someone else during the batch window survives — and on failure
// deletes a row the batch created or writes back the one it overwrote or removed:
//
//   _pp_snapshot_batch_targets  ──▶ {norm_from => exists?, entry}   [lib/actions.php]
//   create_redirect / remove_redirect ──▶ pp_redirects option
//   step fails ──▶ _pp_restore_batch_snapshot_report ──▶ patch ONLY those keys back
//                                             └─ write refused? name the path in
//                                                rollback_errors, never stay silent

const PP_REDIRECTS_OPTION = 'pp_redirects';

/**
 * Normalizes a path or URL to the canonical form used as a redirect map key.
 * Drops scheme/host/query/fragment, forces a single leading slash, strips a
 * trailing slash (root stays "/"). Same normalizer runs on both write (the
 * stored `from`) and read (the incoming request path) so a match can never be
 * missed on a trailing-slash or query-string difference.
 *
 * @param string $path  A path ("/old") or full URL ("https://site/old?x=1").
 * @return string       Canonical path, always starting with "/".
 */
function _pp_normalize_redirect_path(string $path): string {
    $only_path = parse_url(trim($path), PHP_URL_PATH);
    if (!is_string($only_path) || $only_path === '') {
        return '/';
    }
    $only_path = '/' . ltrim($only_path, '/');
    $trimmed = rtrim($only_path, '/');
    return $trimmed === '' ? '/' : $trimmed;
}

/**
 * Validates a redirect target for open-redirect safety: same-site only.
 * Accepts a site-relative path ("/new") or an absolute URL whose host matches
 * the site's home host. Rejects external hosts, protocol-relative "//host",
 * and dangerous schemes (javascript:, data:, vbscript:). wp_safe_redirect() at
 * resolve time re-checks the host allowlist as a runtime backstop.
 *
 * @param string $to  Proposed target.
 * @return true|WP_Error
 */
function _pp_validate_redirect_target(string $to) {
    $to = trim($to);
    if ($to === '') {
        return new WP_Error('invalid_redirect_target', 'Redirect target must not be empty.');
    }
    if (preg_match('#^\s*(?:javascript|data|vbscript)\s*:#i', $to)) {
        return new WP_Error('invalid_redirect_target', 'Redirect target scheme is not allowed.');
    }
    // Protocol-relative "//host/path" points off-site — reject before the
    // leading-slash path check below would treat it as same-site.
    if (strpos($to, '//') === 0) {
        return new WP_Error('external_redirect_target', 'Protocol-relative redirect targets are not allowed; use a same-site path or absolute same-host URL.');
    }
    // Site-relative path.
    if ($to[0] === '/') {
        return true;
    }
    // Absolute URL: host must equal the site's home host.
    $host = parse_url($to, PHP_URL_HOST);
    $home_host = parse_url(home_url('/'), PHP_URL_HOST);
    if (!is_string($host) || $host === '' || !is_string($home_host) || strcasecmp($host, $home_host) !== 0) {
        return new WP_Error('external_redirect_target', 'Redirect target must be a same-site path or an absolute URL on this site.');
    }
    return true;
}

/**
 * Returns the stored redirect map, normalized to the documented shape.
 *
 * @return array<string,array{to:string,code:int}>
 */
function pp_get_redirects(): array {
    $raw = get_option(PP_REDIRECTS_OPTION, []);
    if (!is_array($raw)) {
        return [];
    }
    $out = [];
    foreach ($raw as $from => $entry) {
        if (!is_string($from) || !is_array($entry) || !isset($entry['to'])) {
            continue;
        }
        $code = (int) ($entry['code'] ?? 301);
        $out[$from] = [
            'to'   => (string) $entry['to'],
            'code' => in_array($code, [301, 302], true) ? $code : 301,
        ];
    }
    return $out;
}

/**
 * Resolves an incoming path to its redirect entry, or null if none matches.
 *
 * @param string $path  Incoming request path or URL.
 * @return array{to:string,code:int}|null
 */
function pp_resolve_redirect(string $path): ?array {
    $redirects = pp_get_redirects();
    $norm = _pp_normalize_redirect_path($path);
    return $redirects[$norm] ?? null;
}

/**
 * Detects whether adding from → to would create a redirect loop. Rejects the
 * degenerate from == to case and any multi-hop chain that cycles back, by
 * walking targets (each `to` reduces to a same-site path) with a hop cap.
 *
 * @param string $from_norm  Normalized source path being added.
 * @param string $to         Proposed (validated same-site) target.
 * @param array  $existing   Current redirect map.
 * @return bool  True if the redirect would loop.
 */
function _pp_redirect_would_loop(string $from_norm, string $to, array $existing): bool {
    $visited = [$from_norm => true];
    $cursor = _pp_normalize_redirect_path($to);
    $hops = 0;
    while (true) {
        if (isset($visited[$cursor])) {
            return true;
        }
        if (++$hops > 20) {
            return true;
        }
        $visited[$cursor] = true;
        if (!isset($existing[$cursor]['to'])) {
            return false;
        }
        $cursor = _pp_normalize_redirect_path((string) $existing[$cursor]['to']);
    }
}

/**
 * Creates (or replaces) a redirect from a source path to a same-site target.
 * Validates the target for open-redirect safety and refuses from == to or a
 * chain that would loop.
 *
 * @param string $from  Source path (or URL) to redirect away from.
 * @param string $to    Same-site target path or absolute same-host URL.
 * @param int    $code  301 (default) or 302.
 * @return string|WP_Error  The normalized source path stored, or WP_Error.
 */
function pp_create_redirect(string $from, string $to, int $code = 301) {
    if (!in_array($code, [301, 302], true)) {
        return new WP_Error('invalid_redirect_code', 'Redirect status code must be 301 or 302.');
    }
    $from_norm = _pp_normalize_redirect_path($from);
    if ($from_norm === '/') {
        return new WP_Error('invalid_redirect_source', 'Refusing to redirect the site root.');
    }
    $target_valid = _pp_validate_redirect_target($to);
    if (is_wp_error($target_valid)) {
        return $target_valid;
    }
    $to = trim($to);
    if ($from_norm === _pp_normalize_redirect_path($to)) {
        return new WP_Error('redirect_loop', 'A redirect source and target must differ.');
    }
    $redirects = pp_get_redirects();
    if (_pp_redirect_would_loop($from_norm, $to, $redirects)) {
        return new WP_Error('redirect_loop', 'This redirect would create a loop.');
    }
    $redirects[$from_norm] = ['to' => $to, 'code' => $code];
    update_option(PP_REDIRECTS_OPTION, $redirects);
    return $from_norm;
}

/**
 * Removes a redirect by source path. Returns true if one was removed, false if
 * no redirect existed for that (normalized) source.
 *
 * @param string $from  Source path (or URL).
 * @return bool
 */
function pp_remove_redirect(string $from): bool {
    $from_norm = _pp_normalize_redirect_path($from);
    $redirects = pp_get_redirects();
    if (!isset($redirects[$from_norm])) {
        return false;
    }
    unset($redirects[$from_norm]);
    update_option(PP_REDIRECTS_OPTION, $redirects);
    return true;
}

/**
 * template_redirect resolver. Fires on every front-end request but acts only
 * when WordPress found nothing to render (is_404) — an unmatched request — so a
 * redirect can rescue a renamed/moved URL without ever shadowing a live page.
 * Registered in functions.php.
 */
function pp_redirect_template_hook(): void {
    if (is_admin() || !is_404()) {
        return;
    }
    $request_uri = isset($_SERVER['REQUEST_URI']) ? (string) wp_unslash($_SERVER['REQUEST_URI']) : '';
    if ($request_uri === '') {
        return;
    }
    $match = pp_resolve_redirect($request_uri);
    if ($match === null) {
        return;
    }
    // wp_safe_redirect() re-validates the target host (open-redirect backstop)
    // and escapes the Location header.
    wp_safe_redirect($match['to'], $match['code']);
    exit;
}

// ── Template tags ──────────────────────────────────────────────────────────

/**
 * Loads the comments template (comments.php) for the current post.
 * Wrapper for comments_template() — maintains the invariant that
 * templates call only pp_* functions, never raw WP functions.
 */
function pp_comments_template(): void {
    if (comments_open() || get_comments_number()) {
        comments_template();
    }
}

// ── Default content ─────────────────────────────────────────────────────────

/**
 * Returns the default homepage composition used on fresh installs and as the
 * blank-page fallback. Single source of truth — called by lib/setup.php at
 * activation time and by templates/front-page.php as a render-time safeguard.
 *
 * A curated branded multi-band starter (issue #512): a dark split hero, an
 * audience/problem band, a files-vs-WordPress mechanism band (native text-panel
 * with a monospace spec panel — no inline-HTML), a speed/trust card grid, a
 * maintainability/proof band, and a closing CTA. The branded look (warm-cream
 * surfaces, ink darks, restrained orange accent) is expressed entirely through
 * VALIDATED per-component style slots (top-level `style` key) and native
 * component props — never homepage-only shared CSS (#72) and never render-time
 * mutation. The only HTML string is the hero proof surface, which uses the
 * theme's own `hero__surface-*` classes (theme tokens, no hardcoded colours)
 * rather than one-off inline styling. Every band is schema-valid; the whole
 * composition passes pp_validate_composition() (guarded by SchemaValidationTest).
 *
 * This is the FRESH-INSTALL starter seed only. Theme activation writes it once
 * (pp_setup_homepage), guarded to never overwrite an existing valid static
 * front page — so an already-configured live site keeps its own homepage.
 *
 * Palette (all values are literal, slot-valid):
 *   ink #0A0A12 / #14141F · cream #F2EEE5 / #FBF8F1 · border #E8E2D4
 *   accent #FF5C2E (hover #C73310)
 *
 * @return array  Component array ready for wp_json_encode or direct rendering.
 */
function pp_default_homepage_composition(): array {
    return [
        // 1 — Branded split hero (dark), proof surface via theme classes.
        ['component' => 'hero', 'props' => [
            'id'            => 'home-hero',
            'eyebrow'       => 'Open-source AI-first WordPress theme',
            'title'         => 'Turn AI-assisted site drafts into maintainable WordPress composition.',
            'title_accent'  => 'maintainable',
            'subheading'      => 'PromptingPress gives small WordPress teams a bounded page-composition layer. AI drafts sections, humans inspect props and style slots, and every change can be validated and screenshotted before review.',
            'button_text'      => 'View theme on GitHub',
            'button_url'       => 'https://github.com/FJCF76/PromptingPress',
            // Both hero CTAs are outline: on the dark hero the filled premium
            // `.btn` bevel is a fixed blue gradient driven by the GLOBAL
            // --color-accent/--btn-bg tokens (a fresh install has no token
            // override), so --hero-accent alone cannot repaint it, and the ghost
            // variant's text is not reachable by --hero-button2-color. Outline
            // clears that gradient and tracks --hero-heading-color (cream), hover-filling
            // with --hero-accent (orange) — on-brand, restrained hero CTAs. The
            // one filled orange button stays the closing CTA (--cta-button-bg).
            'button_variant'   => 'outline',
            'button2_text'     => 'See how it works',
            'button2_url'      => '#home-mechanism',
            'button2_variant'  => 'outline',
            'layout'        => 'split',
            'split_ratio'   => '60-40',
            'vertical_align' => 'stretch',
            'proof'         => '<p class="hero__surface-label">Composition workflow</p><div class="hero__surface-list"><div class="hero__surface-item"><span class="hero__surface-key">Read</span><span class="hero__surface-value">Structured site context</span></div><div class="hero__surface-item"><span class="hero__surface-key">Edit</span><span class="hero__surface-value">Bounded props, not builder clutter</span></div><div class="hero__surface-item"><span class="hero__surface-key">Validate</span><span class="hero__surface-value">Screenshot-backed before review</span></div></div>',
        ], 'style' => [
            '--hero-bg'                  => 'radial-gradient(circle at 78% 24%, #3A1D1D 0%, #14141F 44%, #0A0A12 100%)',
            '--hero-heading-color'               => '#F2EEE5',
            '--hero-subheading-color'     => '#E8E2D4',
            '--hero-padding-top'        => '7rem',
            '--hero-padding-bottom'     => '6rem',
            '--hero-content-width'      => '64rem',
            '--hero-heading-size'         => 'clamp(2.75rem, 5vw, 4.75rem)',
            '--hero-accent'             => '#FF5C2E',
            '--hero-accent-hover'       => '#C73310',
            '--hero-heading-accent-color' => '#FF5C2E',
            '--hero-eyebrow-color'      => '#FF5C2E',
            '--hero-eyebrow-bg'         => '#14141F',
            '--hero-eyebrow-border-color' => 'rgba(255, 92, 46, 0.4)',
            '--hero-eyebrow-border-width' => '1px',
            '--hero-radius'             => '0',
            '--hero-surface-bg'         => '#F2EEE5',
            '--hero-surface-border-color' => '#E8E2D4',
            '--hero-surface-radius'     => '4px',
            '--hero-surface-shadow'     => '0 24px 60px rgba(0, 0, 0, 0.28)',
        ]],

        // 2 — Audience / problem band (warm cream), prose + meta strip.
        ['component' => 'section', 'props' => [
            'id'            => 'home-audience',
            'eyebrow'       => 'The cost after launch',
            'title'         => 'AI speeds up the first draft. Teams still own the maintenance.',
            'title_accent'  => 'own the maintenance',
            'subheading'    => 'Speed is not the hard part anymore. The next revision is.',
            'body'          => '<p>An AI page can look done in minutes. Then the team inherits unclear structure, scattered styling, and a page that is hard to inspect the next time a client asks for a change.</p><p>PromptingPress keeps that next edit reviewable: WordPress sections, bounded component props, validated style slots, and screenshots stay in the workflow before anything is treated as done.</p>',
            'body_items'    => ['First-draft speed', 'Revision debt', 'Handoff clarity', 'Safer edits'],
            'layout'        => 'text-only',
        ], 'style' => [
            '--section-bg'                => '#F2EEE5',
            '--section-border-color'      => '#E8E2D4',
            '--section-border-width'      => '1px',
            '--section-heading-size'        => 'clamp(1.9rem, 3vw, 2.9rem)',
            '--section-heading-accent-color' => '#FF5C2E',
            '--section-body-measure'        => '46rem',
            '--section-eyebrow-color'     => '#FF5C2E',
            '--section-eyebrow-bg'        => '#FBF8F1',
            '--section-eyebrow-border-color' => '#E8E2D4',
            '--section-eyebrow-border-width' => '1px',
            '--section-separator-color'   => '#FF5C2E',
        ]],

        // 3 — Mechanism band (native text-panel, monospace dark spec panel).
        ['component' => 'section', 'props' => [
            'id'            => 'home-mechanism',
            'eyebrow'       => 'The composition boundary',
            'title'         => 'Files own the design. WordPress owns the content.',
            'title_accent'  => 'design',
            'subheading'    => 'A clear split is what keeps AI work maintainable after handoff.',
            'body'          => '<p>The repeatable system stays in the theme: templates, components, design tokens, and CSS. The page stays in WordPress as structured composition data an implementer or agent can inspect later.</p><p>Agents get a bounded map of what can change, so edits stay inside a reviewable path.</p>',
            'layout'        => 'text-panel',
            'panel_heading' => 'What lives where',
            'panel_items'   => [
                ['label' => 'Templates & components', 'value' => 'Theme files'],
                ['label' => 'Design tokens & CSS',    'value' => 'Theme files'],
                ['label' => 'Copy & section order',   'value' => 'WordPress'],
                ['label' => 'Component props & slots', 'value' => 'WordPress'],
                ['label' => 'Validation & screenshots', 'value' => 'Review path'],
            ],
        ], 'style' => [
            '--section-bg'                => '#FBF8F1',
            '--section-border-color'      => '#E8E2D4',
            '--section-border-width'      => '1px',
            '--section-heading-size'        => 'clamp(1.9rem, 3vw, 2.9rem)',
            '--section-heading-accent-color' => '#FF5C2E',
            '--section-eyebrow-color'     => '#FF5C2E',
            '--section-eyebrow-bg'        => '#F2EEE5',
            '--section-eyebrow-border-color' => '#E8E2D4',
            '--section-eyebrow-border-width' => '1px',
            '--section-panel-bg'          => '#0A0A12',
            '--section-panel-text'        => '#F2EEE5',
            '--section-panel-border-color' => '#14141F',
            '--section-panel-radius'      => '4px',
            '--section-panel-font'        => 'var(--font-mono)',
        ]],

        // 4 — Speed / trust card grid (dark band, uniform peer cards).
        ['component' => 'grid', 'props' => [
            'id'            => 'home-adoption',
            'eyebrow'       => 'Why teams adopt it',
            'title'         => 'Two reasons: speed and trust.',
            'title_accent'  => 'speed and trust',
            'subheading'    => 'Speed gets the first draft moving. Trust keeps the next revision safe to review.',
            'layout'        => 'cards',
            'card_emphasis' => 'uniform',
            'columns'       => 2,
            'items'         => [
                ['title' => 'Lightweight by default', 'text' => 'Client sites should not carry a heavy visual-builder runtime just because AI helped write the page.', 'bullets' => ['Plain WordPress, PHP, and CSS', 'No builder lock-in', 'Fast front-end']],
                ['title' => 'AI-safe structure', 'text' => 'The next agent pass should not have to guess through hidden state.', 'bullets' => ['Components, IDs, props, slots', 'Inspectable page data', 'No hidden logic']],
                ['title' => 'Readable handoff', 'text' => 'Teams can explain what was built and where the content lives.', 'bullets' => ['A clear section map', 'Content in WordPress', 'No opaque AI artifact']],
                ['title' => 'Safer revisions', 'text' => 'AI-assisted updates on live sites stay easy to trust.', 'bullets' => ['Preflight checks', 'Screenshots before apply', 'Rollback-aware actions']],
            ],
        ], 'style' => [
            '--grid-bg'                  => '#0A0A12',
            '--grid-heading-color'       => '#F2EEE5',
            '--grid-heading-accent-color' => '#FF5C2E',
            '--grid-heading-size'        => 'clamp(1.9rem, 3vw, 2.9rem)',
            '--grid-heading-measure'   => '44rem',
            '--grid-subheading-color'    => '#E8E2D4',
            '--grid-eyebrow-color'       => '#FF5C2E',
            '--grid-eyebrow-bg'          => '#14141F',
            '--grid-eyebrow-border-color' => '#3A2A1E',
            '--grid-eyebrow-border-width' => '1px',
            '--grid-item-bg'             => '#F2EEE5',
            '--grid-item-border-color'         => '#E8E2D4',
            '--grid-item-border-width'   => '1px',
            '--grid-item-radius'         => '4px',
            '--grid-item-bar-color'      => '#FF5C2E',
            '--grid-item-bar-height'     => '3px',
            '--grid-item-shadow'         => '0 18px 38px rgba(0, 0, 0, 0.18)',
            '--grid-item-title-color'    => '#0A0A12',
            '--grid-item-text-color'     => '#3A3A44',
            '--grid-item-bullet-color'        => '#FF5C2E',
        ]],

        // 5 — Maintainability / proof band (warm cream), prose + workflow strip.
        ['component' => 'section', 'props' => [
            'id'            => 'home-proof',
            'eyebrow'       => 'The review path',
            'title'         => 'AI edits leave evidence, not mystery.',
            'title_accent'  => 'leave evidence',
            'subheading'    => 'The first draft only matters if the next change is still inspectable and safe to hand off.',
            'body'          => '<p>Most AI website workflows optimize for the first page. PromptingPress optimizes for the work after it: the next revision, the client change request, the page expansion, and the post-launch handoff all stay inside a reviewable WordPress composition path.</p><p>The agent can inspect the page, edit bounded props, validate the result, render screenshots, and leave a trail before work is treated as done.</p>',
            'body_items'    => ['Inspect', 'Edit bounded props', 'Validate', 'Screenshot', 'Roll back'],
            'layout'        => 'text-only',
        ], 'style' => [
            '--section-bg'                => '#F2EEE5',
            '--section-border-color'      => '#E8E2D4',
            '--section-border-width'      => '1px',
            '--section-heading-size'        => 'clamp(1.9rem, 3vw, 2.9rem)',
            '--section-heading-accent-color' => '#FF5C2E',
            '--section-body-measure'        => '46rem',
            '--section-eyebrow-color'     => '#FF5C2E',
            '--section-eyebrow-bg'        => '#FBF8F1',
            '--section-eyebrow-border-color' => '#E8E2D4',
            '--section-eyebrow-border-width' => '1px',
            '--section-separator-color'   => '#FF5C2E',
        ]],

        // 6 — Closing CTA (dark), branded orange button.
        ['component' => 'cta', 'props' => [
            'id'            => 'home-cta',
            'eyebrow'       => 'Get started',
            'title'         => 'Get the open-source PromptingPress theme.',
            'title_accent'  => 'PromptingPress',
            'body'          => 'PromptingPress is on GitHub for WordPress teams that want an AI-first theme built around inspectable composition, bounded edits, validation, and review evidence.',
            'button_text'   => 'View theme on GitHub',
            'button_url'    => 'https://github.com/FJCF76/PromptingPress',
            'button_variant' => 'primary',
            'layout'        => 'full-width',
        ], 'style' => [
            '--cta-bg'                  => '#0A0A12',
            '--cta-heading-color'                => '#F2EEE5',
            '--cta-body-color'          => '#E8E2D4',
            '--cta-padding-top'         => '5.5rem',
            '--cta-padding-bottom'      => '5.5rem',
            '--cta-heading-measure'       => '48rem',
            '--cta-heading-size'          => 'clamp(2rem, 3vw, 3.1rem)',
            '--cta-heading-accent-color'  => '#FF5C2E',
            '--cta-accent'              => '#FF5C2E',
            '--cta-accent-hover'        => '#C73310',
            '--cta-button-bg'           => '#FF5C2E',
            '--cta-button-color'        => '#0A0A12',
            '--cta-button-hover-bg'     => '#C73310',
            '--cta-button-hover-color'  => '#F2EEE5',
            '--cta-eyebrow-color'       => '#FF5C2E',
            '--cta-eyebrow-bg'          => '#14141F',
            '--cta-eyebrow-border-color' => '#3A2A1E',
            '--cta-eyebrow-border-width' => '1px',
        ]],
    ];
}
