<?php
/**
 * components/faq/faq.php
 *
 * FAQ accordion using native HTML details/summary — zero JS required.
 * Props: see schema.json
 *
 * @var array $props
 */

$id           = $props['id']           ?? '';
// #706: guard BOTH raw-value text arguments of pp_render_heading_with_accent()
// (`string $title`, `string $accent`) before they reach the call below. A non-empty
// array is truthy, so the `if ($title)` gate passes on one and the typed call raises a
// TypeError that no caller catches — the whole PUBLIC PAGE 500s. Argument #2 fatals the
// same way on its own, so both props are guarded, not just the title. Guarded at the
// READ because the gate that decides whether the heading renders at all sits upstream of
// the call, so a guarded-away value renders the band with no heading rather than an
// empty one. is_scalar + (string), NOT is_string: only non-scalars ever fataled
// (coercive mode), and the write path stores a scalar title raw (#707), so is_string()
// would silently drop an accepted value. Full reasoning in components/hero/hero.php.
// Local specifics: faq is the OTHER component with a non-empty `??` default, so note
// what the else-branch is doing — it is '' and NOT 'Frequently Asked Questions'. The
// default fires only when the key is ABSENT; a stored non-scalar is PRESENT, and
// degrading it into that placeholder would paint invented content onto a visitor's page.
// The questions and answers below still render, as does their JSON-LD schema.
$raw_title        = $props['title']        ?? 'Frequently Asked Questions';
$title            = is_scalar($raw_title) ? (string) $raw_title : '';
$raw_title_accent = $props['title_accent'] ?? '';
$title_accent     = is_scalar($raw_title_accent) ? (string) $raw_title_accent : '';
// UNGUARDED, AND THAT IS A FILED GAP RATHER THAN AN OVERSIGHT (#1051). This reaches
// esc_html() below, which is the #736 class: an ARRAY eyebrow paints the literal word
// `Array` into the band, an OBJECT one 500s the page. #736 enumerated its members by
// SINK AND PROP (`title` on logos/table/embed) and #721 covers `hero.proof`; neither
// names this one, and the admitting criterion those issues set means nothing widened
// to cover it implicitly. Closing it HERE would widen a rebuild issue into the
// data-integrity ruling #706 and #736 both declined to widen one prop at a time
// without a census, so #1046 measured it, filed it and left it. See the note below
// `$raw_items` for the same class still open on `$question`, its sibling in this file.
$eyebrow      = $props['eyebrow']      ?? '';
// ── #739: the `items` container guard, the third typed boundary on this prop ──
//
// #708 guarded `items` in grid, where it reaches count(). Here it reaches a DIFFERENT
// typed call — pp_render_faq_schema(array $items), at the bottom of this file — and the
// family's admitting criterion is the same TYPED CALL, not the same prop and not the
// same file, so #708 deliberately left this alone and #739 closes it.
//
// WORSE THAN THE GRID CASE, and that asymmetry is the whole point of the separate
// issue: the schema call sits OUTSIDE the `!empty($items)` gate below. Grid survives a
// falsy `items` because its only boundary is inside its gate; faq does not, so it
// fatals on shapes an empty list is indistinguishable from. Measured, one render per
// shape, stored bytes through the composition render loop:
//
//   'a string' / '' / '0'   TypeError: pp_render_faq_schema(): Argument #1 ($items) must be of type array, string given
//   0 / 42                  ... int given
//   false / true            ... bool given
//   3.14                    ... float given
//   object                  ... Foo given          (raw-serialized meta channel only)
//
// The issue body measured six of those; `true`, `3.14` and the object shape were added
// here after re-deriving the set, so the guard is pinned against nine, not six. Note
// `null` is absent from the list and is NOT a gap: `?? []` fires on it, which is why
// the default is the empty array and must stay that way.
//
// is_array, NOT is_scalar — an array IS the contract at this parameter, so the
// shape-appropriate predicate is the #708 one, exactly as D-B prescribes. Guarding at
// the READ closes the list gate and the schema call together, from one line. Degrades
// to the band rendering its "No questions yet." empty state with no JSON-LD script,
// byte-identical to a band that stored no items at all.
//
// -0.0 DOES NOT APPLY. That trap needs a (string) CAST meeting a truthiness gate; this
// guard performs no cast, so no scalar can change which side of `!empty()` it lands on
// — every non-array is rejected identically. Same reasoning #708 recorded.
//
// WHAT THIS DOES NOT CLOSE, named so the fix is not read as broader than it is:
// pp_render_faq_schema() re-reads each element's `question` and `answer` itself, with
// its own `(string)` cast (lib/wp.php), INDEPENDENTLY of the element guard below. That
// is a different boundary again — a language cast, not a typed call — so it was filed
// separately as #742 rather than widened into this ruling, and #742 has since LANDED:
// the helper now carries its own is_array element guard and is_scalar value guards, so
// a damaged question or answer is skipped from the JSON-LD exactly as a stored-empty
// one always was. What remains open here is the VISIBLE loop below, not the helper:
// `$question` is read UNGUARDED into esc_html(), so an OBJECT question (and an object
// ELEMENT, at the offset read) still 500s this page before the schema call is reached,
// and an ARRAY question still paints the literal `Array` in the summary. That is the
// #736 class (esc_html coercion), still open — as is `$eyebrow` above, filed at #1051.
$raw_items = $props['items'] ?? [];
$items     = is_array($raw_items) ? $raw_items : [];

// ── v2: what stayed a prop, and why ─────────────────────────────────────────
//
// `theme` is GONE. It was a bundle of designable values — a band fill, a pair of
// framing rules, a heading colour — which is exactly what the UDC expresses directly:
// the `_band` role's `background`, `border` and `typography` groups. All three of its
// values were measured before it went, and the route in `retired_props` names all three
// groups rather than only the fill, because `muted` drew borders that a background-only
// route would silently drop.
//
// Every CONTENT prop stays: `id`, `title`, `title_accent`, `eyebrow` and `items` carry
// what the band says, not how it looks. faq has no layout prop, so unlike hero, section
// and cta nothing here survives on "the UDC has no group for it" grounds.
//
// NO `refuse_props_when`, AND THAT IS A CHECKED ANSWER RATHER THAN AN OMISSION. The
// rebuild contract asks for props that are declared, well-typed and stored but paint
// nothing in the configuration the band is in. faq has no layout modes, so no prop can
// be layout-dead. The one genuinely inert pair is `title_accent` without `title`, and
// the shared `applies_when` grammar cannot express it: pp_applies_when_clause_errors()
// bounds predicates to `equals` / `in` / `present`, and pp_applies_when_clause_met()
// reads `present` by KEY and ignores its value, so `present: false` is a synonym rather
// than an inverse. Widening a shared grammar on behalf of one component is not this
// rebuild's call, so it stays filed as #1037.

// ── v2: the band's styling identity ─────────────────────────────────────────
//
// Where v1 read a `__pp_style` map of 21 slots and painted it into an inline `style`
// attribute, this emits one attribute and nothing else: `data-pp-band`. Every designable
// value for this band is in a scoped block in the document head, keyed on that attribute
// (lib/udc.php). No inline style means no specificity cliff — a band's rules and the
// stylesheet's structural rules sit at comparable weight and resolve in source order,
// which is what makes the cascade a cascade.
//
// An absent or malformed id emits NO attribute. That is the whole guard: the engine
// mints ids on WRITE only, so a band that reached storage without one (raw meta, data
// written before the rule, or restore_composition, which reports without blocking per
// #233) must render structurally rather than be handed a fabricated id here. An EMPTY
// attribute would be worse than none — it would make `[data-pp-band=""]` match every
// other id-less band on the page and paint one band's design onto another. The canonical
// reasoning for this pair lives in components/cta/cta.php.
$raw_band  = $props['__pp_udc_band'] ?? '';
$band_id   = (is_scalar($raw_band) && pp_udc_valid_band_id((string) $raw_band)) ? (string) $raw_band : '';
$band_attr = $band_id !== '' ? ' data-pp-band="' . esc_attr($band_id) . '"' : '';

// THE FOCUS RING FOLLOWS THE OVERLAY, AND faq NEEDS IT FOR THE FIRST TIME HERE (#986's
// mechanism, ported at #1046). v1 faq had no `background_image` prop at all, so a scrim
// was not a state this component could reach; `_band` -> `background.image` +
// `background.overlay` makes it one. `--color-accent` measures 1.17:1 over the worst-case
// scrim, a WCAG 1.4.11 failure, and faq's focusable control is the <summary> rather than
// a `.btn` — so the ring rule in components.css names `.faq__question` alongside `.btn`.
// The attribute is emitted by the ENGINE, which is the only thing that knows a scrim is
// being painted: an accessibility affordance is structural, emitted rather than authored,
// and not something an author can forget to switch on.
$overlay_attr = !empty($props['__pp_udc_overlay']) ? ' data-pp-band-overlay' : '';

?>
<section<?php echo $id ? ' id="' . esc_attr($id) . '"' : ''; ?> class="faq" data-pp-component="faq"<?php echo $band_attr; ?><?php echo $overlay_attr; ?>>
    <div class="container">

        <?php if ($eyebrow) : ?>
            <span class="faq__eyebrow"><?php echo esc_html($eyebrow); ?></span>
        <?php endif; ?>

        <?php if ($title) : ?>
            <h2 class="faq__heading"><?php echo pp_render_heading_with_accent($title, $title_accent, 'faq__heading-accent'); ?></h2>
        <?php endif; ?>

        <?php if (!empty($items)) : ?>
            <div class="faq__list">
                <?php foreach ($items as $item) :
                    $question = $item['question'] ?? '';
                    // #730 (element level, distinct from the #739 container guard above):
                    // the answer goes into core's UNTYPED wp_kses_post(), which fatals on
                    // an array and on an object. See components/section/section.php for
                    // that sink and the never-try/catch rule.
                    //
                    // UNGATED, so an EMPTY array fatals as readily as a populated one:
                    // the `if (!$question)` guard above tests the QUESTION, so an item
                    // with a good question and a malformed answer walks straight into the
                    // escaper. Degrades to an empty .faq__answer div, keeping the
                    // question and its <details> disclosure intact — the accordion still
                    // opens, it just has nothing inside, which is what a stored empty
                    // answer has always produced.
                    $raw_answer = $item['answer'] ?? '';
                    $answer     = is_scalar($raw_answer) ? (string) $raw_answer : '';
                    if (!$question) continue;
                ?>
                    <details class="faq__item">
                        <summary class="faq__question">
                            <?php echo esc_html($question); ?>
                        </summary>
                        <div class="faq__answer">
                            <?php echo wp_kses_post($answer); ?>
                        </div>
                    </details>
                <?php endforeach; ?>
            </div>
        <?php else : ?>
            <?php // THE `text-muted` UTILITY IS GONE (#1046), so the `empty` role owns
                  // this line's colour instead of a utility class — the same call #994
                  // made for footer. The measured grey is the role's `typography.color`
                  // default now.
                  //
                  // THE CANONICAL CASCADE REASONING LIVES IN `components/faq/schema.json`
                  // under the `empty` role — the same move the band-id guard above makes
                  // when it points at cta.php for its canonical reasoning. It is stated there once because it is subtle and
                  // this branch has now shipped two wrong drafts of it in this very
                  // comment: the first claimed a layered utility beats any authored
                  // colour (false for a role-aimed write), and the second claimed dropping
                  // the class "left the inherited route open" (also false — the role's own
                  // default is a direct declaration on this element, so an inherited
                  // `_band` colour still loses, which is exactly why a dark band needs a
                  // second write on `empty`). Three copies of that argument is three
                  // places to get it wrong; the schema is the copy under test. ?>
            <p class="faq__empty">No questions yet.</p>
        <?php endif; ?>

    </div>

    <?php
    // FAQPage JSON-LD lives INSIDE the <section>, not after it (#432). A
    // <script> is metadata content valid anywhere in the body flow, and Google
    // reads ld+json from anywhere in the DOM, so SEO is unaffected. Emitting it
    // as a trailing SIBLING of </section> made the script the previous element
    // sibling of the next band, so `main > [data-pp-component] + .band` missed
    // that band and it fell back to its own (larger) top padding. Keeping the
    // script inside the section restores the faq as the following band's
    // immediate component sibling.
    echo pp_render_faq_schema($items);
    ?>
</section>
