<?php
/**
 * components/hero/hero.php
 *
 * Props: see schema.json
 *
 * @var array $props
 */

$id           = $props['id']           ?? '';
// ── #706: the raw-value guard for pp_render_heading_with_accent() ───────────
//
// THE CANONICAL EXPLANATION FOR title/title_accent LIVES HERE. grid, section, cta,
// stats, faq and testimonials carry the same four-line guard with a pointer back to
// this block; keep the reasoning in one place so a correction lands once. It is the
// same idiom components/logos/logos.php documents for image_url (#641) and
// components/cta/cta.php for background_image (#705), ratified as the family
// standard at gate D-B.
//
// BOTH of the helper's text arguments are typed:
//   pp_render_heading_with_accent(string $title, string $accent, string $accent_class)
// and BOTH fatal. Measured on current main, one render per component: a stored array
// `title` raises "Argument #1 ($title) must be of type string, array given" on ALL
// SEVEN components, and a stored array `title_accent` alongside a perfectly good title
// raises the same on Argument #2, again on all seven. (The filed issue claimed
// argument #2 for hero only; it is every one of them, which is why both props are
// guarded everywhere rather than just here.) templates/composition.php:16-26 calls
// pp_get_component() with no try/catch, so ONE malformed stored value returns a
// whole-page 500 instead of a band missing its heading. `title` is on nearly every
// band, so this is the widest blast radius in the theme.
//
// WHY THE GUARD IS AT THE READ AND NOT INSIDE THE HELPER. Widening the helper's
// signature to accept mixed looks like the smaller diff — one file instead of seven —
// and it is the wrong fix. The truthiness gates (`if ($title)`, and the header gates
// that read `$title || $eyebrow || $subheading`) sit UPSTREAM of the call in six of
// the seven components. A stored array is TRUTHY, so a mixed-typed helper would let
// those gates open and emit an empty `<h2>` inside a header wrapper that exists only
// to hold it. Guarding at the read closes the gates instead, which is what D-B asks
// for: the band renders WITHOUT its heading. Relaxing a shared typed boundary to
// absorb bad data is also the opposite of what D-B prescribes — guard BEFORE the call.
//
// HERO IS THE ONE COMPONENT WITH NO GATE, and its degradation is therefore different
// from its six siblings — stated plainly here because this is the canonical block and
// the difference is easy to miss. The call at the `<h1>` below is UNCONDITIONAL, so a
// guarded-away title emits `<h1 class="hero__title"></h1>`: the element, empty. That
// is not a new shape. A stored empty-string title emits exactly those bytes today and
// always has (measured), so the guard makes a non-scalar render identically to a case
// that has shipped since the component existed. Adding an `if ($title)` gate here to
// suppress the empty `<h1>` was considered and deliberately REJECTED: it would change
// rendering for well-formed stored data (an intentionally empty title would stop
// emitting the h1), and D-B requires zero rendering change for well-formed data. The
// honest cost, so nobody discovers it later: corrupt stored data still leaves an empty
// page heading in the accessibility tree. A degraded h1 is a much smaller harm than a
// 500, but it is a real one, and closing it means changing hero's markup contract —
// which needs its own ruling, not a rider on this guard.
//
// THE ELSE-BRANCH IS '', NOT THE DEFAULT ABOVE, and that distinction is unique to this
// prop pair (all three of #705's sites defaulted to ''). `?? 'Default Title'` fires
// only when the key is ABSENT. A stored non-scalar is PRESENT, so it must fall to '',
// never to the placeholder — degrading a corrupt title into the words "Default Title"
// (or, in faq, "Frequently Asked Questions") would paint invented content onto a
// visitor's page. Degrade means render less, never make something up.
//
// is_scalar, NOT is_string. PHP runs COERCIVE here (no declare(strict_types)), so only
// NON-SCALARS ever fataled: a stored `42` coerced at the typed boundary and rendered
// the heading "42". #707 has since narrowed the WRITE path so a scalar title is refused,
// but that gates writes and not storage — a pre-#707 composition, a restore (#233) and a
// raw meta write all still hold it — so is_string() would silently drop a heading that
// renders correctly today. Precisely
// scoped, because "only non-scalars fataled" is slightly too broad as usually stated:
// coercive mode would ALSO have accepted a __toString object, which this guard blanks.
// That is unreachable rather than merely unlikely, and it takes two facts to say so.
// Both composition readers decode with json_decode($raw, true), which yields arrays and
// never objects: pp_composition() (lib/wp.php:225), which is the one
// templates/composition.php actually calls, and pp_get_composition() (lib/wp.php:411)
// by way of pp_get_composition_result() (lib/wp.php:359). And pp_get_component()
// (lib/components.php:19) takes $props as a plain array parameter with NO filter hook,
// so a plugin cannot inject one either. An ARRAY is the only non-scalar these props can
// actually hold on any production path.
//
// "ZERO RENDERING CHANGE FOR WELL-FORMED DATA" IS TRUE BUT NOT SELF-EVIDENT, because
// the guard moves WHERE the coercion happens: from inside the typed call to before the
// truthiness gates. So the question is not whether the cast changes the string (it
// cannot) but whether it changes any gate. Measured across 42, 3.14, true, false, 0,
// '0', '': every one agrees, because PHP's '0' is itself falsy. The single exception is
// FLOAT NEGATIVE ZERO: -0.0 is falsy, but (string) -0.0 is '-0', which is truthy (only
// '' and '0' are falsy strings), so it opens gates it used to leave shut and paints a
// heading reading "-0". HERO IS IMMUNE — no gate, so it already renders '-0' today.
// The six gated components flip.
//
// That exception is REAL BUT BARELY REACHABLE, and what decides it is the stored JSON
// TEXT, not the PHP value that was written:
//
//   json_encode(-0.0)            -> the text `-0`   -> json_decode gives INT 0   -> falsy, NO flip
//   stored text `-0.0` (literal) -> json_decode gives FLOAT -0                   -> truthy, FLIP
//
// PHP's json_encode never emits the decimal-point form, so every writer that re-encodes
// round-trips it to int 0 and renders nothing, exactly as before. Only stored bytes
// already holding the literal text `-0.0` (a raw _pp_composition meta write, a
// hand-edited row) reach the flip. Left alone deliberately, matching #705: '-0' is
// inert once escaped, and special-casing it would mean inspecting and rewriting the
// stored value, which is exactly what D-B forbids. Both channels are pinned in
// tests/StoredTitleRenderGuardTest.php.
//
// STORED data is the point. The write path rejects a non-scalar title, but it gates
// WRITES, not storage: a composition authored before the type rules landed still
// carries the value, restore_composition restores and REPORTS without ever blocking
// (#233), and a raw _pp_composition meta write is not gated at all. Nothing here
// rewrites the store — the value is read, not migrated, and _pp_composition_findings()
// still reports it to the operator.
//
// SCOPE is this prop pair into this one helper. The same defect class through other
// surfaces: #708 (count() on a scalar items, pp_render_style_vars on a non-array style)
// has since LANDED — see the guard further down this file and its canonical block in
// components/grid/grid.php — which matters for the page-level claim made here, because
// pp_render_style_vars() runs BEFORE the heading in these templates. Until #708 landed,
// a band carrying both a non-array `__pp_style` and a bad title still fataled upstream
// of this guard; that door is now shut, so a corrupt style and a corrupt title together
// degrade instead of 500ing (pinned in tests/StoredStyleAndItemsRenderGuardTest.php).
// The corridor is still not fully closed. Open on it: #730 (core's esc_url/wp_kses_post,
// which DO fatal in production), #733 (lib/ai-context.php's mb_strlen/basename on the
// same raw title), #736 (esc_html rendering a stored array as the word `Array`), #739
// (faq's items into pp_render_faq_schema), #740 (an object-valued style slot) and #707
// (what the write path accepts). #738 (grid's `(string) ($index + 1)` on a string items
// key) has LANDED — closed from both ends, the write path refusing a JSON-object `items`
// and grid's card loop counting positions instead of reading the key.
$raw_title        = $props['title']        ?? 'Default Title';
$title            = is_scalar($raw_title) ? (string) $raw_title : '';
$raw_title_accent = $props['title_accent'] ?? '';
$title_accent     = is_scalar($raw_title_accent) ? (string) $raw_title_accent : '';
$eyebrow   = $props['eyebrow']  ?? '';
$subheading  = $props['subheading'] ?? '';
$button_text  = $props['button_text'] ?? '';
// #730: guard both link props before they reach core's UNTYPED esc_url(), which still
// fatals from the inside — ltrim() rejects an array and an object. Full reasoning in
// components/cta/cta.php. Local specifics: both anchors here sit behind text gates
// (`if ($button_text)` and the nested `if ($button2_text)`), never url gates, so the
// (string) cast meets no gate and cannot flip a scalar — verified across the same
// eleven-shape sweep cta documents. A guarded-away url therefore renders the button
// with an empty href rather than removing it, which is the pre-existing meaning of an
// empty stored url here and NOT a new state: the button's visibility has always been
// the text's decision, not the url's.
$raw_button_url  = $props['button_url']  ?? '#';
$button_url      = is_scalar($raw_button_url) ? (string) $raw_button_url : '';
$button2_text = $props['button2_text'] ?? '';
$raw_button2_url = $props['button2_url'] ?? '#';
$button2_url     = is_scalar($raw_button2_url) ? (string) $raw_button2_url : '';
$button_variant  = $props['button_variant']  ?? 'primary';
$button2_variant = $props['button2_variant'] ?? 'outline';
$layout    = $props['layout']    ?? 'centered';
// #641: guard BOTH raw-value arguments of pp_render_responsive_image() (`string $url`,
// `string $alt`) before they reach it. A non-empty array is truthy, so every image gate
// below passes on one and the typed call raises a TypeError that no caller catches — the
// whole PUBLIC PAGE 500s. is_scalar + (string), NOT is_string: only non-scalars ever
// fataled (coercive mode), and the write path stores a scalar image_url raw (#707), so
// is_string() would silently drop an accepted value. Full reasoning in
// components/logos/logos.php. Same STORED-data reachability as the image_id guard below
// (#233 restore, pre-rule compositions, raw meta).
//
// Hero is the one that reaches TWO typed helpers on this single prop: the split layout's
// pp_render_responsive_image() and the cover layout's pp_esc_image_src() background-image.
// Guarding at the READ covers both, because everything below reads $image_url and never
// the raw prop again. A guarded-away image_url means a cover hero paints its overlay with
// no background image, and a split hero falls to the "left" layout by the SHIPPED #440
// rule below — but ONLY when it also has no resolvable image_id and no proof, because
// $has_split_media counts an attachment as media in its own right. Render-time only: the
// stored `layout` prop is not rewritten.
$raw_image_url = $props['image_url'] ?? '';
$image_url = is_scalar($raw_image_url) ? (string) $raw_image_url : '';
$raw_image_alt = $props['image_alt'] ?? '';
$image_alt = is_scalar($raw_image_alt) ? (string) $raw_image_alt : '';
// is_numeric() BEFORE the (int) cast (#614, extended to the top-level readers at
// gate 7A). `(int)` is not a rejection: `(int) ['attachment_id' => 42]` and
// `(int) true` both evaluate to 1, so a bare cast resolves attachment ID 1 —
// usually the site's first upload — and discards the author's image_url. The write
// path rejects that shape (image_id declares `type: number`, gated by the #507
// pass), but the validator gates WRITES: restore_composition reports without
// blocking (#233) and a composition authored before the rule still reaches this
// line. Sharper here than anywhere else, because $has_split_media below reads
// `$image_id > 0`, so the coercion would also flip the band's LAYOUT to split.
$raw_image_id = $props['image_id'] ?? 0;
$image_id     = is_numeric($raw_image_id) ? (int) $raw_image_id : 0;
$spacing         = $props['spacing']         ?? 'default';
$width           = $props['width']           ?? 'default';
$split_ratio     = $props['split_ratio']     ?? '50-50';
$vertical_align  = $props['vertical_align']  ?? 'center';
$proof           = $props['proof']           ?? '';

// Validate layout.
$allowed_layouts = ['left', 'centered', 'split', 'cover'];
if (!in_array($layout, $allowed_layouts, true)) {
    $layout = 'centered';
}

// Validate CTA button variants (same shared .btn--* primitive as components/cta/cta.php).
$allowed_button_variants = ['primary', 'secondary', 'outline', 'ghost'];
if (!in_array($button_variant, $allowed_button_variants, true)) {
    $button_variant = 'primary';
}
if (!in_array($button2_variant, $allowed_button_variants, true)) {
    $button2_variant = 'outline';
}
// primary is the bare .btn; other variants add a .btn--{variant} modifier.
$cta_variant_class  = $button_variant !== 'primary' ? ' btn--' . $button_variant : '';
$cta2_variant_class = $button2_variant !== 'primary' ? ' btn--' . $button2_variant : '';

// Validate spacing/width props.
$allowed_spacings = ['default', 'compact', 'spacious'];
if (!in_array($spacing, $allowed_spacings, true)) {
    $spacing = 'default';
}
$allowed_widths = ['default', 'narrow', 'full'];
if (!in_array($width, $allowed_widths, true)) {
    $width = 'default';
}

// Validate new composition props.
$allowed_split_ratios = ['50-50', '60-40', '40-60'];
if (!in_array($split_ratio, $allowed_split_ratios, true)) {
    $split_ratio = '50-50';
}
// 'stretch' is split-oriented (#477): the media item fills the split row's
// height so one asset balances any headline length. It is accepted for the
// shared enum; only the split layout gives it CSS meaning (see components.css).
// On cover it renders as 'center' (no rule), which is harmless.
$allowed_vertical_aligns = ['top', 'center', 'bottom', 'stretch'];
if (!in_array($vertical_align, $allowed_vertical_aligns, true)) {
    $vertical_align = 'center';
}

$proof_markup        = trim((string) $proof);

// Graceful degradation (#440): the "split" layout only makes sense when the
// second column has something to show — an image or the proof surface.
// pp_render_responsive_image() resolves an attachment from image_id even when
// image_url is empty, so a resolvable image_id counts as media here (and the
// image branch below renders it). When a split hero has neither image nor
// proof, the two-column grid would reserve an empty half-band, so fall back to
// the single-column "left" layout: text renders at full content width. This is
// render-time only — the stored layout prop is unchanged (no schema change).
// !empty($image_url) matches the original image-branch truthy gate byte-for-byte
// (an empty string and the falsy "0" both count as "no image", as before).
$has_split_media  = (!empty($image_url) || $image_id > 0);
$effective_layout = ($layout === 'split' && !$has_split_media && $proof_markup === '')
    ? 'left'
    : $layout;

$spacing_attr        = $spacing !== 'default' ? ' data-pp-spacing="' . esc_attr($spacing) . '"' : '';
$width_attr          = $width !== 'default' ? ' data-pp-width="' . esc_attr($width) . '"' : '';
$split_ratio_attr    = ($effective_layout === 'split' && $split_ratio !== '50-50') ? ' data-pp-split-ratio="' . esc_attr($split_ratio) . '"' : '';
$vertical_align_attr = (in_array($effective_layout, ['cover', 'split'], true) && $vertical_align !== 'center') ? ' data-pp-vertical-align="' . esc_attr($vertical_align) . '"' : '';

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
$slot_style = pp_render_style_vars($style, 'hero');

// Build inline style attribute: merge slot vars + cover background-image.
$inline_styles = [];
if ($slot_style) {
    $inline_styles[] = $slot_style;
}
if ($layout === 'cover' && $image_url) {
    $inline_styles[] = 'background-image:url(' . pp_esc_image_src($image_url) . ')';
}
$style_attr = $inline_styles ? ' style="' . implode('; ', $inline_styles) . ';"' : '';
?>
<section<?php echo $id ? ' id="' . esc_attr($id) . '"' : ''; ?> class="hero hero--<?php echo esc_attr($effective_layout); ?>" data-pp-component="hero"<?php echo $spacing_attr; ?><?php echo $width_attr; ?><?php echo $split_ratio_attr; ?><?php echo $vertical_align_attr; ?><?php echo $style_attr; ?>>
    <?php if ($layout === 'cover') : ?>
        <div class="hero__overlay" aria-hidden="true"></div>
    <?php endif; ?>
    <div class="container">
        <div class="hero__inner">
            <div class="hero__content">
                <?php if ($eyebrow) : ?>
                    <span class="hero__eyebrow"><?php echo esc_html($eyebrow); ?></span>
                <?php endif; ?>
                <h1 class="hero__title"><?php echo pp_render_heading_with_accent($title, $title_accent, 'hero__title-accent'); ?></h1>

                <?php if ($subheading) : ?>
                    <p class="hero__subtitle"><?php echo esc_html($subheading); ?></p>
                <?php endif; ?>

                <?php if ($button_text) : ?>
                    <div class="hero__cta-group">
                        <a href="<?php echo esc_url($button_url); ?>" class="hero__cta btn<?php echo esc_attr($cta_variant_class); ?>">
                            <?php echo esc_html($button_text); ?>
                        </a>
                        <?php if ($button2_text) : ?>
                            <a href="<?php echo esc_url($button2_url); ?>" class="hero__cta hero__cta--secondary btn<?php echo esc_attr($cta2_variant_class); ?>">
                                <?php echo esc_html($button2_text); ?>
                            </a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <?php if ($proof_markup && $effective_layout !== 'split') : ?>
                    <div class="hero__proof"><?php echo wp_kses_post($proof_markup); ?></div>
                <?php endif; ?>
            </div>

            <?php if ($effective_layout === 'split' && $proof_markup) : ?>
                <div class="hero__surface" aria-label="Product workflow surface">
                    <?php echo wp_kses_post($proof_markup); ?>
                </div>
            <?php elseif ($effective_layout === 'split' && $has_split_media) : ?>
                <div class="hero__image-wrap">
                    <?php echo pp_render_responsive_image($image_url, $image_alt, 'hero__image', 'eager', $image_id); ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</section>
