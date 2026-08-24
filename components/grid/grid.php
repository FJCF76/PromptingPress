<?php
/**
 * components/grid/grid.php
 *
 * Card grid for discrete content objects (posts, features, team members, etc.).
 * NOT for icon-in-circle decoration. Every card must represent real content.
 * Props: see schema.json
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
$title_align = $props['title_align'] ?? 'start';
// ── #708: the raw-value guard for count($items) ────────────────────────────
//
// THE CANONICAL EXPLANATION FOR BOTH #708 AXES LIVES IN THIS FILE — this block for
// `items`, and the `__pp_style` block further down. grid is the only component that
// carries both, which is why the reasoning is kept here rather than split.
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
// fataled, so is_scalar was the predicate that changed nothing for write-accepted
// values. Here an ARRAY IS the contract: count() accepts array|Countable and
// nothing else, every scalar fatals, and the write path already says the same
// thing ("prop \"items\" must be an array"). The D-B ruling names this explicitly
// as the shape-appropriate equivalent. -0.0, the one scalar that flips a
// truthiness gate through the (string) cast in the is_scalar idiom, cannot arise
// here: no cast is performed, and a float is rejected like every other scalar.
//
// GUARDED AT THE READ, because the read is upstream of the `!empty($items)` gate.
// A guarded-away value therefore closes that gate, so the band renders no `<ul>`,
// no `data-pp-count` and no cards, and falls through to the existing empty state
// (`<p class="grid__empty text-muted">`) — byte-identical to a grid authored with no
// items at all, which is the coherent degradation the ruling asks for. Widening the
// count() call (`is_array($items) ? count($items) : 0`) would instead emit an empty
// list element carrying `data-pp-count="0"`, a shape no valid composition produces.
//
// WHAT THIS GUARD DOES NOT MAKE SAFE, stated precisely rather than as a general
// "arrays are fine" — every claim below was measured, and two of them are open bugs:
//
//   - An ASSOCIATIVE array passes through untouched, because count() accepts one and
//     rejecting it here would change behaviour for data this ruling does not cover.
//     Whether it RENDERS depends on the elements: `$item_number` below reads
//     `$item['number'] ?? (string) ($index + 1)`, and `??` short-circuits, so the
//     arithmetic runs only for an element that omits `number`. Measured: an
//     associative list whose every element carries `number` renders a full band; one
//     element without it raises "Unsupported operand types: string + int" on the
//     non-numeric key — a whole-page 500. Filed as #738.
//   - Malformed ELEMENTS of a well-formed list are a different boundary again, and
//     they are NOT uniformly safe. A card's text-ish reads (`title`, `text`, `label`)
//     reach esc_html(), which degrades an array to the literal word `Array` plus an
//     E_WARNING — the warn-not-fatal class, #736. But a card's `link_url` reaches
//     core's esc_url(), which DOES fatal: measured, a stored `link_url` of `["x"]`
//     raises "ltrim(): Argument #1 ($string) must be of type string, array given" and
//     500s the page. That is #730, still open. So the residual risk on a well-formed
//     items list includes a fatal, not merely a stray `Array`.
//   - The admitting criterion for this family is the same TYPED CALL, not the same
//     prop. `items` reaches a DIFFERENT typed parameter in faq
//     (pp_render_faq_schema(array $items)), which still fatals, and on falsy shapes
//     that grid survives because that call sits outside its `!empty()` gate. Filed as
//     #739; the drift catcher in tests/InvariantTest.php is keyed on the call for
//     exactly this reason, so that gap is visible rather than assumed covered.
$raw_items = $props['items'] ?? [];
$items     = is_array($raw_items) ? $raw_items : [];
$layout  = $props['layout']  ?? 'cards';
$theme   = $props['theme']   ?? 'default';
$card_emphasis = $props['card_emphasis'] ?? 'featured';

$allowed_layouts = ['cards', 'steps'];
if (!in_array($layout, $allowed_layouts, true)) {
    $layout = 'cards';
}

$allowed_card_emphasis = ['featured', 'uniform'];
if (!in_array($card_emphasis, $allowed_card_emphasis, true)) {
    $card_emphasis = 'featured';
}

$allowed_title_aligns = ['start', 'center'];
if (!in_array($title_align, $allowed_title_aligns, true)) {
    $title_align = 'start';
}

// Explicit desktop column-count override (issue 379). Write-time validation
// (pp_validate_composition_errors) already rejects out-of-range/non-integer
// values, so this is a defensive coercion for raw-written state (mirroring the
// layout/card_emphasis in_array guards above; theme via pp_theme_class, #442): only an integer 1-4 emits
// the data-pp-columns attribute the CSS reads; anything else falls through to
// the auto-by-count grain, so unset output stays byte-identical.
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
$header_align_class = $title_align === 'center' ? ' grid__header--center' : '';

$is_steps      = $layout === 'steps';
$layout_class  = $is_steps ? ' grid--steps' : '';
// theme coercion lives in pp_theme_class(); `muted` emits the legacy `--dark` class (#570 DG-4).
$theme_class   = pp_theme_class($theme, 'grid');
// 'uniform' opts the first card out of the featured emphasis so every card
// renders identically (issue 226). Default 'featured' emits no class, keeping
// existing pages byte-identical. The featured CSS selectors carry a
// :not(.grid--uniform) guard, so this class makes the first card fall through
// to the shared all-cards rules.
$emphasis_class = $card_emphasis === 'uniform' ? ' grid--uniform' : '';

// Item image treatment (issue 380). 'icon' renders each card image at a small
// fixed icon size (--grid-item-icon-size) above the title instead of the default
// 16:9 cover banner. Write-time validation (pp_validate_composition_errors) rejects
// invalid values via the schema strict-enum check; this in_array guard mirrors the
// layout/theme/card_emphasis guards above for raw-written state, so an invalid value
// falls through to 'banner' and output stays byte-identical. Icon treatment is a
// cards concept: steps renders no item images, so it is inert on steps (no dead
// class leaks onto steps markup), keeping steps byte-identical too. Default 'banner'
// emits no class, so existing pages render identically.
$image_treatment = $props['image_treatment'] ?? 'banner';
$allowed_image_treatments = ['banner', 'icon'];
if (!in_array($image_treatment, $allowed_image_treatments, true)) {
    $image_treatment = 'banner';
}
$image_treatment_class = ($image_treatment === 'icon' && !$is_steps) ? ' grid--image-icon' : '';

// Style slot overrides (per-instance visual customization). The card link/button
// follows the card's --grid-item-text-align via the derived --pp-grid-link-align
// plumbing property (issue 361), so a centered card centers its link too; it is
// appended here at grid level and per card below so cascade proximity holds.
// ── #708: the raw-value guard for the __pp_style map ───────────────────────
//
// THE CANONICAL EXPLANATION FOR `__pp_style` LIVES HERE. hero, section, cta, stats,
// faq, testimonials, logos, table and embed carry the same two-line guard with a
// pointer back to this block; keep the reasoning in one place so a correction lands
// once. Same family and same ruling as #641 (image_url), #705 (background_image)
// and #706 (title/title_accent), with the shape-appropriate predicate.
//
// The typed boundary:
//   pp_render_style_vars(array $style, string $component_name, bool $item_scope = false)
// Ten components read `__pp_style` from stored props and hand it straight in.
// Measured on current main, one render per component: a stored string `__pp_style`
// raises "Argument #1 ($style) must be of type array, string given" on ALL TEN —
// hero, grid, section, cta, stats, faq, testimonials, logos, table and embed. The
// filed issue said "all five image-bearing components"; re-deriving the call set
// from source shows it is every component that declares a style slot.
//
// THE ISSUE'S STATED MECHANISM IS NOT THE REACHABLE ONE, and the distinction
// decides where the guard goes. #708 says templates/composition.php "promotes a
// stored `style` map to the `__pp_style` prop" unchecked. It does not: all four
// promotion sites already read
//   $style = isset($item['style']) && is_array($item['style']) ? $item['style'] : [];
// (templates/composition.php:21, templates/front-page.php:75,
// lib/post-apply-validate.php:68, lib/admin.php:3520), so a non-array TOP-LEVEL
// `style` is dropped before any component sees it. A guard added at the promotion
// would fix nothing. The value that actually arrives is `__pp_style` stored INSIDE
// `props`: the promotion only OVERWRITES that key when a valid array-valued
// top-level `style` exists, so a stored item {"props":{"__pp_style":"red"}} walks
// straight through. That is also why the findings engine already reports this shape
// as `unknown_prop` — inside props, `__pp_style` is an undeclared prop — while the
// page still 500s. So the READ inside each component is the only boundary that both
// exists and is reachable, and that is where the guard sits.
//
// is_array, NOT is_scalar: an ARRAY is the contract at this parameter, exactly as
// for count($items) above. Degradation is total and silent by construction —
// pp_render_style_vars() returns '' for an empty map at its own `if (empty($style))`
// guard, and every one of the ten call sites emits its `style` attribute only when
// that return value is non-empty. So a malformed map renders the band with NO
// inline custom properties and NO style attribute: byte-identical to a band that
// stored no style at all (pinned by rendering both and comparing).
//
// ONE READ, TWO TYPED BOUNDARIES — the local reason this file matters. The same raw
// value was read a second time below for pp_grid_link_align_decl(array $style),
// which is typed identically and fatals identically; it is unreachable today only
// because the call above throws first. Both now consume the guarded local, so the
// second boundary cannot be reopened by deleting the first. Reading once is also
// what the drift catcher in tests/InvariantTest.php enforces, so a future third
// consumer cannot quietly reintroduce a raw read.
//
// An array-valued `__pp_style` inside props still renders, unchanged. It is an
// undeclared prop the write path rejects, but pp_render_style_vars() already gates
// every declaration through the #330 render boundary and the declared-slot filter:
// a slot NAME must match the component's own schema exactly, and the VALUE must pass
// _pp_forbidden_css_construct plus the slot's declared grammar. So it cannot paint
// anything a valid style map could not (measured against an adversarial map, and
// pinned in tests/StoredStyleAndItemsRenderGuardTest.php rather than only argued
// here). Blocking it would extend this ruling rather than apply it.
//
// The guard checks the CONTAINER, not its elements, and one element shape is still
// fatal: pp_style_declaration_renders() does `(string) $value` on a declared slot's
// stored value, so an OBJECT value raises "Object of class stdClass could not be
// converted to string". Array values are safe (warning, then dropped). Filed as #740
// — a different boundary inside a well-formed map, not the map itself.
$raw_style = $props['__pp_style'] ?? null;
$style     = is_array($raw_style) ? $raw_style : [];

$grid_style_parts = [];
$slot_style       = pp_render_style_vars($style, 'grid');
if ($slot_style !== '') {
    $grid_style_parts[] = $slot_style;
}
$grid_link_align = pp_grid_link_align_decl($style);
if ($grid_link_align !== '') {
    $grid_style_parts[] = $grid_link_align;
}
$style_attr = $grid_style_parts ? ' style="' . implode('; ', $grid_style_parts) . ';"' : '';

?>
<section<?php echo $id ? ' id="' . esc_attr($id) . '"' : ''; ?> class="grid<?php echo esc_attr($layout_class); ?><?php echo esc_attr($theme_class); ?><?php echo esc_attr($emphasis_class); ?><?php echo esc_attr($image_treatment_class); ?>" data-pp-component="grid"<?php echo $style_attr; ?>>
    <div class="container">

        <?php if ($title || $eyebrow || $subheading) : ?>
            <div class="grid__header<?php echo esc_attr($header_align_class); ?>">
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
                <?php foreach ($items as $index => $item) :
                    $item_number = $item['number']    ?? (string)($index + 1);
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
                    $text_role   = $item['text_role'] ?? '';
                    $allowed_text_roles = ['mono', 'meta', 'label', 'kicker'];
                    $text_role_class = in_array($text_role, $allowed_text_roles, true) ? ' text-' . $text_role : '';

                    // Per-item style overrides (issue 306): render this card's `style`
                    // map as inline custom properties on the .grid__item element,
                    // validated against the SAME grid style slots as grid-level style.
                    // The consuming CSS reads var(--slot, fallback), so a per-item slot
                    // set here overrides the grid-level value by cascade proximity.
                    // A per-card --grid-item-text-align also derives the card's
                    // --pp-grid-link-align companion (issue 361); appended on the
                    // .grid__item so it overrides any grid-level companion by
                    // cascade proximity, exactly like the text-align slot itself.
                    $item_style       = is_array($item['style'] ?? null) ? $item['style'] : [];
                    // Item scope (#579): only the card-scoped (item_eligible) slots
                    // may be emitted here, matching what the write path accepts.
                    $item_style_vars  = pp_render_style_vars($item_style, 'grid', true);
                    $item_link_align  = pp_grid_link_align_decl($item_style);
                    $item_style_parts = [];
                    if ($item_style_vars !== '') {
                        $item_style_parts[] = $item_style_vars;
                    }
                    if ($item_link_align !== '') {
                        $item_style_parts[] = $item_link_align;
                    }
                    $item_style_attr = $item_style_parts ? ' style="' . implode('; ', $item_style_parts) . ';"' : '';
                ?>
                    <li class="grid__item"<?php echo $item_style_attr; ?>>
                        <?php if ($is_steps) : ?>
                            <span class="grid__step-number"><?php echo esc_html($item_number); ?></span>
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
                            <?php if ($item_title) : ?>
                                <h3 class="grid__item-title"><?php echo esc_html($item_title); ?></h3>
                            <?php endif; ?>

                            <?php if ($item_text) : ?>
                                <?php // Inline-HTML supporting-text prop (#439): a/strong/em/br
                                      // allowed and sanitized; block/script tags stripped. The
                                      // link sits inside the LIGHT card, so it keeps --color-accent. ?>
                                <p class="grid__item-text<?php echo esc_attr($text_role_class); ?>"><?php echo pp_kses_inline($item_text); ?></p>
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
            <p class="grid__empty text-muted">Nothing here yet.</p>
        <?php endif; ?>

    </div>
</section>
