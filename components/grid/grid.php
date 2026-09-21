<?php
/**
 * components/grid/grid.php
 *
 * Card grid for discrete content objects (posts, features, team members, etc.).
 * NOT for icon-in-circle decoration. Every card must represent real content.
 * Props: see schema.json
 *
 * THE LAST COMPONENT OFF THE v1 STYLE-SLOT SYSTEM (#1101). What left this file
 * is as much of the story as what stayed: two inline `style` sinks, a theme
 * class, an alignment class, an emphasis class, an image-treatment class and a
 * per-card text-role class. Every one of them was a way of saying "paint this
 * differently", and v2 has exactly one way of saying that — a `udc` map, band
 * grain or item grain, emitted as scoped rules in the document head.
 *
 * @var array $props
 */

$id            = $props['id']            ?? '';
// #706: guard BOTH raw-value text arguments of pp_render_heading_with_accent()
// (`string $title`, `string $accent`) before they reach the call below. A non-empty
// array is truthy, so the `if ($title)` gate passes on one and the typed call raises a
// TypeError that no caller catches — the whole PUBLIC PAGE 500s. Argument #2 fatals the
// same way on its own, so both props are guarded, not just the title. Guarded at the
// READ because the gates that decide whether the heading renders at all sit upstream of
// the call, so a guarded-away value renders the band with no heading rather than an
// empty one. is_scalar + (string), NOT is_string: only non-scalars ever fataled
// (coercive mode), and the write path stores a scalar title raw (#707), so is_string()
// would silently drop an accepted value. Full reasoning in components/hero/hero.php.
// Local specifics: `$title` drives TWO gates here — the `grid__header` wrapper gate
// below (`$title || $eyebrow || $subheading`) and the heading itself — and the read is
// upstream of both, so a grid whose only header content was a malformed title emits no
// header wrapper at all. Distinct from the per-card `$item['title']` read further down,
// which reaches esc_html() and not this helper, so it is deliberately NOT guarded here.
$raw_title         = $props['title']         ?? '';
$title             = is_scalar($raw_title) ? (string) $raw_title : '';
$raw_title_accent  = $props['title_accent']  ?? '';
$title_accent      = is_scalar($raw_title_accent) ? (string) $raw_title_accent : '';
$eyebrow       = $props['eyebrow']       ?? '';
$subheading    = $props['subheading']    ?? '';
// ── #708: the raw-value guard for count($items) ────────────────────────────
//
// THE CANONICAL EXPLANATION FOR THE `items` AXIS LIVES IN THIS FILE. The file used
// to carry BOTH #708 axes — this one and a `__pp_style` map guard — and it was the
// only component that did. The second axis RETIRED WITH THE SLOT SYSTEM at #1101:
// there is no `__pp_style` read here any more, so there is no raw value to guard and
// no call to the typed style-vars helper left in this file. (Its name is deliberately
// not spelled out: UdcEngineTest scans the RAW template for that identifier to prove a
// v2 component emits no inline style attribute, and a comment naming it would fail the
// guard while the file is in fact clean.) The canonical reasoning for that axis lives
// with the components still holding the v1 story; nothing was deleted from the record.
//
// `count()` is typed by PHP itself:
//   count(Countable|array $value, int $mode = COUNT_NORMAL): int
// and the `data-pp-count` attribute below feeds it `$items` straight from stored
// props. A stored SCALAR is truthy, so the `if (!empty($items))` gate that guards
// the list opens, and the very first thing inside it raises
// "count(): Argument #1 ($value) must be of type Countable|array, string given".
// Measured on current main, one render per shape: a stored `items` of "a string",
// 42 and true all fatal. templates/composition.php:16-26 calls pp_get_component()
// with no try/catch, so that TypeError is a 500 for the WHOLE PUBLIC PAGE, not a
// grid with a missing list.
//
// is_array, NOT is_scalar — and that is the difference from #641/#705/#706. Those
// three guard values headed for a `string` parameter, where PHP's coercive mode
// (no declare(strict_types) anywhere in this theme) means only NON-scalars ever
// fataled, so is_scalar was the predicate that changed nothing for the scalars sitting
// in storage. Here an ARRAY IS the contract: count() accepts array|Countable and
// nothing else, every scalar fatals, and the write path already says the same
// thing ("prop \"items\" must be an array"). The D-B ruling names this explicitly
// as the shape-appropriate equivalent. -0.0, the one scalar that flips a
// truthiness gate through the (string) cast in the is_scalar idiom, cannot arise
// here: no cast is performed, and a float is rejected like every other scalar.
//
// GUARDED AT THE READ, because the read is upstream of the `!empty($items)` gate.
// A guarded-away value therefore closes that gate, so the band renders no `<ul>`,
// no `data-pp-count` and no cards, and falls through to the existing empty state
// (`<p class="grid__empty">`) — byte-identical to a grid authored with no
// items at all, which is the coherent degradation the ruling asks for. Widening the
// count() call (`is_array($items) ? count($items) : 0`) would instead emit an empty
// list element carrying `data-pp-count="0"`, a shape no valid composition produces.
//
// WHAT THIS GUARD DOES NOT MAKE SAFE, stated precisely rather than as a general
// "arrays are fine" — every claim below was measured:
//
//   - An ASSOCIATIVE array passes through THIS guard untouched, because count()
//     accepts one and rejecting it here would change behaviour for data this ruling
//     does not cover. It used to take the page down one line below instead:
//     `$item_number` read `$item['number'] ?? (string) ($index + 1)`, and `??`
//     short-circuits, so the arithmetic ran only for an element omitting `number` —
//     an associative container whose every element carried one rendered a full band,
//     while a single element without one raised "Unsupported operand types: string +
//     int" on the non-numeric key and 500'd the whole page. CLOSED by #738, from both
//     ends: the write path now refuses a JSON-object `items` (a declared
//     `type: "array"` must be a list), and this file's card loop derives the ordinal
//     from a positional counter instead of the key, so an ALREADY-STORED map renders
//     its cards numbered 1..N. See the counter's own comment at the loop.
//   - Malformed ELEMENTS of a well-formed list are a different boundary again, and
//     they are NOT uniformly safe. A card's text-ish reads (`title`, `text`, `label`)
//     reach esc_html(), which degrades an array to the literal word `Array` plus an
//     E_WARNING — the warn-not-fatal class, #736. But a card's `link_url` reaches
//     core's esc_url(), which DOES fatal: measured, a stored `link_url` of `["x"]`
//     raises "ltrim(): Argument #1 ($string) must be of type string, array given" and
//     500s the page. That is #730, guarded at its own read below. So the residual risk
//     on a well-formed items list includes a fatal, not merely a stray `Array`.
//   - The admitting criterion for this family is the same TYPED CALL, not the same
//     prop. `items` reaches a DIFFERENT typed parameter in faq
//     (pp_render_faq_schema(array $items)), which still fatals, and on falsy shapes
//     that grid survives because that call sits outside its `!empty()` gate. Filed as
//     #739; the drift catcher in tests/InvariantTest.php is keyed on the call for
//     exactly this reason, so that gap is visible rather than assumed covered.
$raw_items = $props['items'] ?? [];
$items     = is_array($raw_items) ? $raw_items : [];
$layout  = $props['layout']  ?? 'cards';

$allowed_layouts = ['cards', 'steps'];
if (!in_array($layout, $allowed_layouts, true)) {
    $layout = 'cards';
}

// Explicit desktop column-count override (issue 379). Write-time validation
// (pp_validate_composition_errors) already rejects out-of-range/non-integer
// values, so this is a defensive coercion for raw-written state (mirroring the
// layout in_array guard above): only an integer 1-4 emits the data-pp-columns
// attribute the CSS reads; anything else falls through to the auto-by-count
// grain, so unset output stays byte-identical.
// $is_steps is computed below; forward-declare the steps check here so a forced
// column count is inert on steps at the RENDER layer too, not only via the CSS
// :not(.grid--steps) scope — steps keeps its fixed process grain, so its markup
// stays byte-identical (no dead data-pp-columns attribute leaks onto it).
$columns_is_steps = ($layout === 'steps');
$columns_raw = $props['columns'] ?? '';
$columns = (is_int($columns_raw) || (is_string($columns_raw) && preg_match('/^\d+$/', $columns_raw)))
    ? (int) $columns_raw
    : 0;
$columns_attr = (!$columns_is_steps && $columns >= 1 && $columns <= 4)
    ? ' data-pp-columns="' . esc_attr((string) $columns) . '"'
    : '';

$is_steps      = $layout === 'steps';
$layout_class  = $is_steps ? ' grid--steps' : '';

// ── v2: the band's styling identity ─────────────────────────────────────────
//
// Where v1 read a `__pp_style` map and painted it into an inline `style`
// attribute, this emits one attribute and nothing else: `data-pp-band`. Every
// designable value for this band is in a scoped block in the document head,
// keyed on that attribute (lib/udc.php). No inline style means no specificity
// cliff — a band's rules and the stylesheet's structural rules sit at
// comparable weight and resolve in source order, which is what makes the
// cascade a cascade. Same guard, same reasoning, same shape as testimonials'.
//
// An absent or malformed id emits NO attribute. That is the whole guard: the
// engine mints ids on WRITE only, so a band that reached storage without one
// (raw meta, data written before the rule, or restore_composition, which reports
// without blocking per #233) must render structurally rather than be handed a
// fabricated id here. An EMPTY attribute would be worse than none — it would
// make `[data-pp-band=""]` match every other id-less band on the page and paint
// one band's design onto another.
$raw_band  = $props['__pp_udc_band'] ?? '';
$band_id   = (is_scalar($raw_band) && pp_udc_valid_band_id((string) $raw_band)) ? (string) $raw_band : '';
$band_attr = $band_id !== '' ? ' data-pp-band="' . esc_attr($band_id) . '"' : '';

?>
<section<?php echo $id ? ' id="' . esc_attr($id) . '"' : ''; ?> class="grid<?php echo esc_attr($layout_class); ?>" data-pp-component="grid"<?php echo $band_attr; ?>>
    <div class="container">

        <?php if ($title || $eyebrow || $subheading) : ?>
            <div class="grid__header">
                <?php if ($eyebrow) : ?>
                    <span class="grid__eyebrow"><?php echo esc_html($eyebrow); ?></span>
                <?php endif; ?>
                <?php if ($title) : ?>
                    <h2 class="grid__heading"><?php echo pp_render_heading_with_accent($title, $title_accent, 'grid__heading-accent'); ?></h2>
                <?php endif; ?>
                <?php if ($subheading) : ?>
                    <p class="grid__subheading"><?php echo esc_html($subheading); ?></p>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($items)) : ?>
            <ul class="grid__list" role="list" data-pp-count="<?php echo esc_attr(count($items)); ?>"<?php echo $columns_attr; ?>>
                <?php
                // THE ORDINAL COMES FROM A POSITIONAL COUNTER, NOT THE ARRAY KEY (#738).
                //
                // This loop used to read `foreach ($items as $index => $item)` and fall
                // back to `(string) ($index + 1)`. For a LIST the key IS the position and
                // the arithmetic is fine; for a JSON OBJECT map the key is a STRING, and
                // `"first" + 1` raises "Unsupported operand types: string + int".
                // templates/composition.php:16-26 calls pp_get_component() with no
                // try/catch, so that TypeError is a 500 for the whole PUBLIC page —
                // measured, not inferred. `??` short-circuits, so a map whose every entry
                // carries `number` renders a full band and the page dies only once an
                // author deletes one field.
                //
                // WHY A COUNTER RATHER THAN AN is_int() GUARD, which is what the sibling
                // guards in this family (#641/#705/#706/#708) spell. Those guard a value
                // whose only honest degradation is ABSENCE — a corrupt image_url has no
                // right answer, so the image is dropped. Here there IS a right answer:
                // `$index + 1` was only ever spelling "the 1-based position of this card",
                // and a counter says that directly for every container shape. An
                // `is_int($index)` guard would instead emit an EMPTY step badge on a map,
                // which is a visibly broken band where a correct one is available for the
                // same three lines. Degrade means the page survives with the best honest
                // rendering, not the barest one.
                //
                // BYTE-IDENTICAL FOR EVERY LIST, which is the property that makes this
                // safe to land on data that renders today: for a list, position == key + 1
                // at every element. Only the shape that was already fataling changes, and
                // it changes from a 500 to cards numbered 1..N. Pinned by rendering a map
                // and the equivalent list and comparing, in
                // tests/StoredGridItemsMapRenderGuardTest.php.
                //
                // STILL A DEGRADATION, NOT A REPAIR. Nothing here rewrites stored data,
                // and since #738 the WRITE path refuses this shape outright (a declared
                // `type: "array"` prop must be a JSON list), so the diagnostic engine names
                // the band and prop while the renderer keeps the page up — the same
                // division of labour the whole guard family uses. What reaches here is what
                // a write gate cannot: pre-rule compositions, `restore_composition` (#233),
                // and raw `_pp_composition` meta writes.
                //
                // The KEY IS DELIBERATELY UNUSED, and `$index` is gone rather than left
                // bound-but-ignored: an unused key is how the next reader reintroduces
                // arithmetic on it. Nothing else in this loop ever read it (measured).
                $item_position = 0;
                foreach ($items as $item) :
                    $item_position++;
                    $item_number = $item['number']    ?? (string) $item_position;
                    $item_title  = $item['title']     ?? '';
                    $item_text   = $item['text']      ?? '';
                    $bullets     = is_array($item['bullets'] ?? null) ? $item['bullets'] : [];
                    // #641: guard BOTH raw-value arguments of pp_render_responsive_image()
                    // (`string $url`, `string $alt`) before they reach it. A non-empty array
                    // is truthy, so the `if ($image_url && !$is_steps)` gate below passes on
                    // one and the typed call raises a TypeError that no caller catches — the
                    // whole PUBLIC PAGE 500s. is_scalar + (string), NOT is_string: only
                    // non-scalars ever fataled (coercive mode), and the write path stores a
                    // scalar image_url raw (#707), so is_string() would silently drop an
                    // accepted value AND its resolvable image_id attachment with it. Full
                    // reasoning in components/logos/logos.php. Same STORED-data reachability
                    // as the image_id guard below (#233 restore, pre-rule compositions, raw
                    // meta). Here a guarded-away image means the card renders its body with
                    // no image wrap, exactly as an empty image_url already does.
                    $raw_image_url = $item['image_url'] ?? '';
                    $image_url     = is_scalar($raw_image_url) ? (string) $raw_image_url : '';
                    $raw_image_alt = $item['image_alt'] ?? '';
                    $image_alt     = is_scalar($raw_image_alt) ? (string) $raw_image_alt : '';
                    // Responsive card image (issue 584): the attachment-ID companion the
                    // hero, section and logos images already carry.
                    // is_numeric() BEFORE the (int) cast, deliberately. `(int)` is not a
                    // rejection: `(int) ['attachment_id' => 42]` and `(int) true` both
                    // evaluate to 1, so the plain cast would render attachment ID 1 —
                    // usually the site's first upload — and discard the author's image_url.
                    // #614 closed the WRITE path (a nested field's declared scalar type is
                    // enforced now), but this guard is what covers STORED data: the
                    // validator gates writes, and restore_composition reports without
                    // blocking (#233), so a composition written before that rule still
                    // reaches this line. Guarding at the read makes a malformed value mean
                    // "no attachment", which is what every other bad value already means.
                    $raw_image_id = $item['image_id'] ?? 0;
                    $image_id     = is_numeric($raw_image_id) ? (int) $raw_image_id : 0;
                    // #730, and this is the ELEMENT-level instance the family had not
                    // reached before: the container `items` is guarded by #708, but a
                    // single malformed CARD inside an otherwise well-formed list still
                    // carries its own raw value to core's esc_url(). Measured: a stored
                    // link_url of ["x"] raises "ltrim(): Argument #1 ($string) must be of
                    // type string, array given" and 500s the whole page, which is why the
                    // #708 landing recorded "the residual risk on a well-formed items list
                    // includes a fatal, not merely a stray Array". Full reasoning in
                    // components/cta/cta.php.
                    //
                    // -0.0 APPLIES HERE, unlike at cta's and hero's ungated sites. The
                    // anchor below is gated on `if ($link_url)`, so the cast meets a
                    // truthiness gate, and float -0.0 is the one scalar where they
                    // disagree: -0.0 is falsy but (string) -0.0 is '-0', and only ''
                    // and '0' are falsy strings. So a card storing -0.0 starts rendering
                    // a link it previously omitted. Left as-is, following the #705
                    // precedent that shipped exactly this flip: json_encode never emits
                    // the decimal-point form (json_encode(-0.0) is the text `-0`, which
                    // decodes back to INT 0 and stays falsy), so only stored bytes that
                    // already contain the literal text -0.0 reach it. Special-casing it
                    // would mean inspecting and rewriting the stored value, which is what
                    // D-B forbids. Both channels are pinned rather than asserted.
                    $raw_link_url = $item['link_url'] ?? '';
                    $link_url     = is_scalar($raw_link_url) ? (string) $raw_link_url : '';
                    $link_text   = $item['link_text'] ?? 'Read more';

                    // ── v2: THIS CARD's styling identity (Addendum B2/B3) ───────
                    //
                    // The item tier's `data-pp-band` — one level down, on the element
                    // the component declares as `item_roles.root` (`card`, i.e. this
                    // `<li>`). It replaces the per-card inline `style` attribute v1
                    // painted here from `items[].style`, which §3.4 forbids outright.
                    //
                    // THE GUARD IS THE BAND GUARD, and it has to be, for a sharper
                    // version of the same reason. Ids are minted on WRITE only and
                    // only for entries that carry a `udc` map, so an entry reaching
                    // this loop without a valid one is either plain content (the
                    // common case — most cards are never styled individually) or
                    // stored data no write gate saw. Both render structurally. An
                    // EMPTY attribute would be strictly worse than none here than it
                    // is at band grain: `[data-pp-item=""]` inside a band scope would
                    // match every OTHER unstyled card in the same band and paint one
                    // card's design onto all of them.
                    //
                    // pp_udc_valid_item_id() is stricter than its band counterpart on
                    // purpose (`it-` + exactly eight lowercase hex digits, `\z`-
                    // anchored): a band id may have been authored, an item id never
                    // was. Nothing else in this file may relax that — the same string
                    // is interpolated into a CSS attribute selector by the emitter.
                    $raw_item_id = $item['id'] ?? '';
                    $item_id     = (is_scalar($raw_item_id) && pp_udc_valid_item_id((string) $raw_item_id))
                        ? (string) $raw_item_id
                        : '';
                    $item_attr   = $item_id !== '' ? ' data-pp-item="' . esc_attr($item_id) . '"' : '';
                ?>
                    <li class="grid__item"<?php echo $item_attr; ?>>
                        <?php // The card top bar (#1101, ruling D5). A REAL ELEMENT in v2 where
                              // v1 painted it as `.grid__item::before`, and the change is what
                              // saved the capability: ruling A3 defers pseudo-elements, so no
                              // role could have addressed a `::before`, and the brand signature
                              // measured on 10 of 11 production bands would have retired with
                              // the slots. As a span it is the `card-bar` role, addressable at
                              // band grain and at item grain through ordinary `background.fill`
                              // and `sizing.height` — no grammar widening, no contract change.
                              // Decorative and empty, so it is hidden from assistive technology
                              // rather than announced as a blank list item child.
                              // Not rendered on steps: v1's bar rule carried a
                              // `:not(.grid--steps)` scope, and a steps card leads with its
                              // number badge. ?>
                        <?php if (!$is_steps) : ?>
                            <span class="grid__item-bar" aria-hidden="true"></span>
                        <?php endif; ?>

                        <?php if ($image_url && !$is_steps) : ?>
                            <div class="grid__item-image-wrap">
                                <?php // Responsive image (issue 584), the same helper
                                      // logos.php, hero.php and section.php
                                      // already call. A resolvable image_id renders through
                                      // wp_get_attachment_image() with real srcset/sizes;
                                      // unset or unresolvable, the helper emits exactly
                                      // today's single-source <img> — same src, alt, class
                                      // and loading, so the paint is unchanged either way.
                                      // The `$image_url` gate above is deliberately kept
                                      // (matching logos.php): image_id is a companion to a
                                      // URL, never a replacement for one. ?>
                                <?php echo pp_render_responsive_image($image_url, $image_alt, 'grid__item-image', 'lazy', $image_id); ?>
                            </div>
                        <?php endif; ?>

                        <div class="grid__item-body">
                            <?php // THE STEP BADGE MOVED INSIDE THE BODY (#1101), and it is a
                                  // forced move rather than a tidy-up. v1 rendered it as a direct
                                  // child of `.grid__item` and relied on a steps-only outer
                                  // padding — `.grid--steps .grid__item { padding: 2rem 1rem 1rem }`
                                  // — to keep it off the card edge. That padding has no v2 home:
                                  // the boundary admits no non-zero padding in this stylesheet,
                                  // and a role's `defaults` carry a breakpoint and a state
                                  // dimension but NO variant dimension, so "padding, but only on
                                  // steps" is unspellable (the conditionality gap filed at #1102).
                                  // Left outside the body with that padding gone, the badge would
                                  // sit flush against the card's border.
                                  //
                                  // Inside the body it is inset by `card-body` -> `spacing.padding`
                                  // like every other card child, which also makes the desktop
                                  // steps connector land EXACTLY on the badge's centre-line for
                                  // the first time: the connector's `calc(var(--space-lg) +
                                  // 1.375rem)` is 32px + half a 44px badge = 54px, and the badge's
                                  // centre is now the body's 2rem padding plus the same 22px.
                                  // Under v1 it was approximating.
                                  //
                                  // WHAT CHANGED VISUALLY, stated rather than discovered: a steps
                                  // card's content inset goes from 48px to 32px at the sides (the
                                  // outer padding no longer stacks with the body's), the badge's
                                  // own inset goes from 16px to 32px, and the gap under the badge
                                  // goes from 16px to 24px because `card-body`'s flex `gap` now
                                  // applies between the badge and the title as well. The
                                  // `step-number` role's measured `margin-bottom` default is kept
                                  // as measured rather than shaved to hide the difference. ?>
                            <?php if ($is_steps) : ?>
                                <span class="grid__step-number"><?php echo esc_html($item_number); ?></span>
                            <?php endif; ?>

                            <?php if ($item_title) : ?>
                                <h3 class="grid__item-title"><?php echo esc_html($item_title); ?></h3>
                            <?php endif; ?>

                            <?php if ($item_text) : ?>
                                <?php // Inline-HTML supporting-text prop (#439): a/strong/em/br
                                      // allowed and sanitized; block/script tags stripped.
                                      // The v1 `text_role` preset class is gone (#1101): its
                                      // four values were mono/meta/label/kicker, two of which
                                      // measured byte-identical to the default above 767px, and
                                      // per-card typography is what an item `udc` map expresses. ?>
                                <p class="grid__item-text"><?php echo pp_kses_inline($item_text); ?></p>
                            <?php endif; ?>

                            <?php if (!empty($bullets)) : ?>
                                <ul class="grid__item-bullets">
                                    <?php foreach ($bullets as $bullet) :
                                        if (!is_string($bullet) || $bullet === '') {
                                            continue;
                                        }
                                    ?>
                                        <li class="grid__item-bullet"><?php echo esc_html($bullet); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>

                            <?php if ($link_url) : ?>
                                <a href="<?php echo esc_url($link_url); ?>" class="grid__item-link">
                                    <?php echo esc_html($link_text); ?>
                                </a>
                            <?php endif; ?>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php else : ?>
            <?php // THE `text-muted` UTILITY IS GONE (#1101), so the `empty` role owns
                  // this line's colour instead of a utility class — the same call faq
                  // made at #1046, table at #1066 and footer at #994. The measured grey
                  // is the role's `typography.color` default now.
                  //
                  // IT IS NOT A TIDY-UP. `utilities.css` sits in `@layer pp-v1` and a
                  // role's DEFAULT tier emits into `@layer pp-zero`, strictly below it,
                  // so a surviving `.text-muted` would outrank the `empty` role's own
                  // default outright — layer rank beats specificity, and the role would
                  // have been declared and dead on the one element it names. ?>
            <p class="grid__empty">Nothing here yet.</p>
        <?php endif; ?>

    </div>
</section>
