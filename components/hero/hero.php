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
// v2 NARROWED WHAT THIS PROP REACHES, and the guard got simpler with it. On v1 it fed
// TWO typed helpers: the split layout's pp_render_responsive_image() and the cover
// layout's pp_esc_image_src() background-image. A band background is now the `_band`
// role's `background.image` — an attachment id the ENGINE resolves (ruling A2) — so
// this prop reaches exactly one helper, in one layout. A guarded-away image_url means a
// split hero falls to the "left" layout by the SHIPPED #440 rule below, but ONLY when it
// also has no resolvable image_id and no proof, because $has_split_media counts an
// attachment as media in its own right. Render-time only: the stored `layout` prop is
// not rewritten.
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
$split_ratio     = $props['split_ratio']     ?? '50-50';
$vertical_align  = $props['vertical_align']  ?? 'center';
$proof           = $props['proof']           ?? '';

// Validate layout.
$allowed_layouts = ['left', 'centered', 'split', 'cover'];
if (!in_array($layout, $allowed_layouts, true)) {
    $layout = 'centered';
}

// Validate the structural composition props.
//
// `spacing` and `width` are GONE in v2, and so are `button_variant` /
// `button2_variant`. Each was a bundle of designable values — vertical padding, a
// content measure, a set of button colours — which is exactly what the UDC expresses
// directly: `_band` spacing, the `content` role's `sizing.max-width`, and a `_preset`
// on the CTA roles. What REMAINS a prop is what the UDC has no group for: `layout`,
// `split_ratio` and `vertical_align` select grid geometry and cross-axis alignment,
// and the taxonomy carries no layout group, so removing them would delete the
// capability rather than move it.
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

$split_ratio_attr    = ($effective_layout === 'split' && $split_ratio !== '50-50') ? ' data-pp-split-ratio="' . esc_attr($split_ratio) . '"' : '';
$vertical_align_attr = (in_array($effective_layout, ['cover', 'split'], true) && $vertical_align !== 'center') ? ' data-pp-vertical-align="' . esc_attr($vertical_align) . '"' : '';

// ── v2: the band's styling identity ─────────────────────────────────────────
//
// Where v1 read a `__pp_style` map of 49 slots and painted it into an inline
// `style` attribute — plus, on the cover layout, an inline `background-image`
// built from `image_url` — this emits one attribute and nothing else:
// `data-pp-band`. Every designable value for this band is in a scoped block in
// the document head, keyed on that attribute (lib/udc.php). No inline style means
// no specificity cliff: a band's rules and the stylesheet's structural rules sit
// at comparable weight and resolve in source order, which is what makes the
// cascade a cascade. A band background image is now the `_band` role's
// `background.image` (ruling A2), so it is authorable on EVERY layout rather than
// only on `cover`, and the engine — not this file — builds the url().
//
// An absent or malformed id emits NO attribute. That is the whole guard: the
// engine mints ids on WRITE only, so a band that reached storage without one
// (raw meta, data written before the rule, or restore_composition, which reports
// without blocking per #233) must render structurally rather than be handed a
// fabricated id here. An EMPTY attribute would be worse than none — it would
// make `[data-pp-band=""]` match every other id-less band on the page and paint
// one band's design onto another. Full reasoning in
// components/testimonials/testimonials.php.
$raw_band  = $props['__pp_udc_band'] ?? '';
$band_id   = (is_scalar($raw_band) && pp_udc_valid_band_id((string) $raw_band)) ? (string) $raw_band : '';
$band_attr = $band_id !== '' ? ' data-pp-band="' . esc_attr($band_id) . '"' : '';

// The engine's overlay hook (#986). Present only when this band paints a scrim over
// a background image, which is the condition under which a focus ring needs the
// on-overlay colour rather than the accent (1.17:1 over a dark scrim is a WCAG
// 1.4.11 failure). It replaces v1's `.hero--cover` keying, which could not follow a
// background image onto the other layouts once ruling A2 made it authorable on all
// of them. Emitted by the engine, never by an author — see
// pp_udc_promote_band_identity().
$overlay_attr = !empty($props['__pp_udc_overlay']) ? ' data-pp-band-overlay' : '';

?>
<section<?php echo $id ? ' id="' . esc_attr($id) . '"' : ''; ?> class="hero hero--<?php echo esc_attr($effective_layout); ?>" data-pp-component="hero"<?php echo $split_ratio_attr; ?><?php echo $vertical_align_attr; ?><?php echo $band_attr; ?><?php echo $overlay_attr; ?>>
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
                        <?php // The primary CTA carries its own modifier so the `cta` role has a
                              // flat class selector. A role selector's charset admits no `:`, so
                              // `.hero__cta:not(.hero__cta--secondary)` is not expressible — and a
                              // modifier is the clearer contract anyway. ?>
                        <a href="<?php echo esc_url($button_url); ?>" class="hero__cta hero__cta--primary btn">
                            <?php echo esc_html($button_text); ?>
                        </a>
                        <?php if ($button2_text) : ?>
                            <a href="<?php echo esc_url($button2_url); ?>" class="hero__cta hero__cta--secondary btn">
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
