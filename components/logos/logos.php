<?php
/**
 * components/logos/logos.php
 *
 * A flex-wrap image grid. Use for client logos (no labels) or icon-category
 * tiles (with labels). Items always have an image; labels are optional.
 * Props: see schema.json
 *
 * @var array $props
 */

$id      = $props['id']      ?? '';
$title   = $props['title']   ?? '';
// The container guard, following faq's precedent at #1046 — see the longer note in
// components/stats/stats.php, which this change made in the same pass. A scalar `items`
// is unreachable through the write path (#744 refuses it) and reachable through stored
// state, exactly like the retired props.
$raw_items = $props['items']   ?? [];
$items   = is_array($raw_items) ? $raw_items : [];

// ── v2: the band id, and the one attribute this template emits for it (#1066) ──
//
// logos declares ROLES, not style slots, so there is no `__pp_style` map to render and no
// `style` attribute at all - #708's guard left with the map it guarded. The engine
// compiles the band's `udc` map into a band-scoped block in the document head; this
// template's whole contribution is saying WHICH band.
//
// THE GUARD IS THE POINT, AND AN EMPTY ATTRIBUTE WOULD BE WORSE THAN NO ATTRIBUTE:
// `[data-pp-band=""]` matches every other id-less band on the page, so a malformed stored
// id would paint one band's design onto all of them. Reachable from stored data even
// though the engine mints on WRITE - a raw `_pp_composition` meta write is not gated, and
// restore_composition reports without blocking (#233). Validate, then emit or emit
// nothing. Pinned behaviourally in StatsLogosV2BandContractTest.
//
// WHAT THIS GUARD DOES NOT DO, stated because the engine's own docblock overstates it:
// `pp_udc_promote_band_identity()` says a band with no usable id "promotes NOTHING, so the
// component emits no `data-pp-band`". That is true of the PROMOTION but not of the props —
// it never clears a `__pp_udc_band` already present, so a stored one passes this charset
// check on its way through and the band can wear ANOTHER band's compiled block. The check
// here is a grammar check, not a provenance check; only the engine can tell a minted id
// from a copied one. Filed as #1073 with the overlay half.
$raw_band  = $props['__pp_udc_band'] ?? '';
$band_id   = (is_scalar($raw_band) && pp_udc_valid_band_id((string) $raw_band)) ? (string) $raw_band : '';
$band_attr = $band_id !== '' ? ' data-pp-band="' . esc_attr($band_id) . '"' : '';

// THE ENGINE DECIDES whether a scrim is being painted; the template just consumes the flag.
//
// TWO HONEST LIMITS, both surfaced by #1066's adversarial pass, because the comment that
// stood here claimed a benefit this component cannot have:
//
// 1. IT HAS NO CONSUMER ON THIS BAND TODAY. The attribute's only readers are
//    `[data-pp-band-overlay] .btn:focus` and `… .faq__question:focus`. Neither stats nor
//    logos renders a `.btn`, a `.faq__question`, or ANY focusable child — so there is no
//    focus ring here to switch, and the 1.17:1 contrast defect the old comment cited
//    (#986's mechanism, #1035's defect) is unreachable on these two components. It is
//    emitted for consistency with the other v2 bands and to be correct the day one of
//    these grows a focusable child, not because it fixes something now.
// 2. THE FLAG IS AN INPUT-SHAPED PROP AND IS NOT VALIDATED AGAINST THE COMPILED MAP. The
//    engine sets it, but `pp_udc_promote_band_identity()` never CLEARS a value already in
//    `$props`, so a raw `_pp_composition` write or a restore (#233) can carry a forged
//    `__pp_udc_overlay` and switch the hook on a band painting no scrim at all. Filed as
//    #1073 rather than patched here: the fix belongs in the engine's promotion step, which
//    is shared by all v2 templates, and a local guard here would leave the other nine.
$overlay_attr = !empty($props['__pp_udc_overlay']) ? ' data-pp-band-overlay' : '';
?>
<section<?php echo $id ? ' id="' . esc_attr($id) . '"' : ''; ?> class="logos" data-pp-component="logos"<?php echo $band_attr; ?><?php echo $overlay_attr; ?>>
    <div class="container">

        <?php if ($title) : ?>
            <h2 class="logos__heading"><?php echo esc_html($title); ?></h2>
        <?php endif; ?>

        <?php if (!empty($items)) : ?>
            <ul class="logos__list" role="list">
                <?php foreach ($items as $item) :
                    // ── #641: the raw-value guard for pp_render_responsive_image() ──────
                    //
                    // THE CANONICAL EXPLANATION LIVES HERE. hero, section, grid and
                    // testimonials carry the same two-line guard with a pointer back to this
                    // block; keep the reasoning in one place so a correction lands once.
                    //
                    // BOTH of the helper's raw-value arguments are typed:
                    //   pp_render_responsive_image(string $url, string $alt, ...)
                    // A non-empty array is TRUTHY, so the `if ($image_url)` gate below PASSES
                    // on one and the typed call raises a TypeError. templates/composition.php
                    // calls pp_get_component() with no try/catch, so ONE malformed stored
                    // value returns a whole-page 500 instead of a band missing an image.
                    // Guarding only $url would leave the identical fatal one argument over in
                    // the same statement, so both are guarded (gate 7A: the admitting
                    // criterion is the same TYPED CALL, not the same file — the sibling props
                    // that reach OTHER typed helpers are #705/#706/#708, deliberately not
                    // here). Of those, #705 (background_image into pp_esc_image_src) and
                    // #706 (title/title_accent into pp_render_heading_with_accent) have
                    // since LANDED and carry their own canonical blocks in
                    // components/stats/stats.php and components/hero/hero.php respectively
                    // (#705's block lived in components/cta/cta.php until #1026 retired that
                    // component's background_image prop and moved it to the last declarer);
                    // #708 (the `__pp_style` map into the style-vars renderer, and grid's
                    // count($items)) has since LANDED too, and this file carries its
                    // guard at the top; its canonical block is in
                    // components/grid/grid.php. #738 (an associative `items` map fataling
                    // grid's ordinal arithmetic) has LANDED too — write refusal plus a
                    // positional counter, both explained in that same file. Still open on
                    // this corridor: #730, #733, #736, #739 and #740.
                    // NOTE this file reads `title` too, and it
                    // is deliberately NOT guarded: logos passes it to esc_html(), which
                    // does not fatal, so it never met #706's admitting criterion.
                    //
                    // is_scalar, NOT is_string, and the difference is load-bearing. PHP runs
                    // COERCIVE here (no declare(strict_types)), so only NON-SCALARS ever
                    // fataled — a stored int/float/bool already coerced at the boundary and
                    // painted. #707 has since narrowed the WRITE path so `image_url: 42` is
                    // refused, but it gates writes, not storage — a pre-#707 composition, a
                    // restored snapshot (#233) and a raw meta write all still hold it. So
                    // is_string() would silently DROP a value that renders correctly today
                    // — and because the helper resolves $attachment_id before it
                    // falls back to $url, it would also discard a perfectly good image_id
                    // attachment on this component. The (string) cast keeps every scalar
                    // rendering byte-for-byte as it does today; the only shapes whose output
                    // changes are the ones that used to fatal, and they change to "no image",
                    // which is what an empty value has always meant here.
                    //
                    // STORED data is the point. The write path rejects non-scalars, but it
                    // gates WRITES, not storage: restore_composition reports without blocking
                    // (#233), a composition authored before the rule still carries the value,
                    // and a raw _pp_composition meta write is not gated at all. A stricter
                    // front door does not repair a page that already stores the bad value.
                    // Nothing here rewrites the store — the value is read, not migrated.
                    $raw_image_url = $item['image_url'] ?? '';
                    $image_url     = is_scalar($raw_image_url) ? (string) $raw_image_url : '';
                    $raw_image_alt = $item['image_alt'] ?? '';
                    $image_alt     = is_scalar($raw_image_alt) ? (string) $raw_image_alt : '';
                    // #614: `(int)` is not a rejection. `(int) ['attachment_id' => 42]`
                    // and `(int) true` both evaluate to 1, so a bare cast resolved
                    // attachment ID 1 — usually the site's FIRST upload — and threw the
                    // author's image_url away. The write path rejects that shape now, but
                    // this guard is what covers STORED data: the validator gates writes,
                    // and restore_composition reports without blocking (#233), so a
                    // composition written before the rule still reaches this line. Same
                    // one-liner grid.php and testimonials.php carry (#584).
                    $raw_image_id = $item['image_id'] ?? 0;
                    $image_id     = is_numeric($raw_image_id) ? (int) $raw_image_id : 0;
                    $label     = $item['label']     ?? '';
                ?>
                    <?php if ($image_url) : ?>
                        <li class="logos__item<?php echo $label ? ' logos__item--labeled' : ''; ?>">
                            <?php echo pp_render_responsive_image($image_url, $image_alt, 'logos__image', 'lazy', $image_id); ?>
                            <?php if ($label) : ?>
                                <span class="logos__label"><?php echo esc_html($label); ?></span>
                            <?php endif; ?>
                        </li>
                    <?php endif; ?>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

    </div>
</section>
