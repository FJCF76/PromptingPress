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
$eyebrow      = $props['eyebrow']      ?? '';
$theme        = $props['theme']        ?? 'default';
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
// #736 class (esc_html coercion), still open.
$raw_items = $props['items'] ?? [];
$items     = is_array($raw_items) ? $raw_items : [];

// Tone variant. Clamp to the known set so an unknown value renders as the
// default surface rather than emitting an unstyled `faq--<garbage>` class
// (mirrors section.php / grid.php — composition validation does not check
// enum values, so the render is the actual contract).
// theme coercion lives in pp_theme_class(); `muted` emits the legacy `--dark` class (#570 DG-4).
$theme_class = pp_theme_class($theme, 'faq');

// Style slot overrides (per-instance visual customization).
// #708: guard the raw `__pp_style` map before it reaches the typed
// pp_render_style_vars(array $style, ...). A stored non-array raises a TypeError that
// no caller catches, so the whole PUBLIC PAGE 500s. It arrives as `__pp_style` stored
// INSIDE props: all four top-level `style` promotions are already is_array guarded, so
// this read is the only reachable boundary and the only place a guard can help.
// is_array, NOT is_scalar — an array IS the contract at this parameter. Degrades to no
// inline custom properties and no `style` attribute at all, byte-identical to a band
// that stored no style. Full reasoning in components/grid/grid.php.
$raw_style = $props['__pp_style'] ?? null;
$style     = is_array($raw_style) ? $raw_style : [];
$slot_style = pp_render_style_vars($style, 'faq');
$style_attr = $slot_style ? ' style="' . $slot_style . ';"' : '';
?>
<section<?php echo $id ? ' id="' . esc_attr($id) . '"' : ''; ?> class="faq<?php echo esc_attr($theme_class); ?>" data-pp-component="faq"<?php echo $style_attr; ?>>
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
            <p class="faq__empty text-muted">No questions yet.</p>
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
