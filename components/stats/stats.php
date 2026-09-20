<?php
/**
 * components/stats/stats.php
 *
 * A row of large-number metrics with labels. Use for quantified social proof
 * or at-a-glance credential statements (e.g. "+30 years experience").
 * Props: see schema.json
 *
 * @var array $props
 */

$id               = $props['id']               ?? '';
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
// Local specifics: the heading is the only thing `$title` drives here, so a malformed
// one costs exactly the `<h2>` — the numbers below still render. The background_image
// guard below is #705's, a different prop into a different typed helper.
$raw_title        = $props['title']            ?? '';
$title            = is_scalar($raw_title) ? (string) $raw_title : '';
$raw_title_accent = $props['title_accent']     ?? '';
$title_accent     = is_scalar($raw_title_accent) ? (string) $raw_title_accent : '';
// THE CONTAINER GUARD, following faq's precedent at #1046 and grid's. `foreach` over a
// scalar raises `foreach() argument must be of type array|object` — a visible PHP warning
// on a public page, not a fatal, but a warning is still breakage on an install with
// display_errors on and it floods a log on every render. The write path refuses a scalar
// under a declared `array` prop (#744), so this is reachable only through stored state: a
// raw `_pp_composition` meta write, or a restore (#233 reports without blocking). That is
// the same reachability argument the retired props above rest on, so it gets the same
// treatment rather than being assumed away. Surfaced by #1066's outside adversarial pass,
// which reported it as a FATAL on the item offsets — measured in PHP 8.3, `$item['x'] ?? ''`
// on a scalar returns the default and does NOT fatal, so the field reads were already safe
// and this guard is about the loop itself.
$raw_items        = $props['items']            ?? [];
$items            = is_array($raw_items) ? $raw_items : [];
// ── v2: `theme` AND `background_image` BOTH RETIRED (#1066) ──────────────────
//
// THE #705 CANONICAL GUARD BLOCK LEFT WITH THE PROP IT GUARDED, and this note is the
// forwarding address rather than a deletion. #705 documented the raw-value guard for
// `background_image` into the typed pp_esc_image_src(string $url, ...): a non-empty
// array is TRUTHY, so the gate passed on one and the typed call fatalled the whole
// public page. That block lived in components/cta/cta.php from #705 until #1026 retired
// cta's prop, then moved HERE because stats was the last declarer. stats is rebuilt now,
// so the prop leaves the theme entirely and the guard has nothing left to guard.
//
// WHAT REPLACED IT, so the reasoning is not merely lost: a band background is the `_band`
// role's `background.image`, whose value is a Media Library ATTACHMENT ID rather than a
// url string, validated by the engine before it is ever interpolated. The whole class of
// defect #705 described - a stored scalar of the wrong shape reaching a typed escaper -
// is closed by the value being an int, not by a per-template guard.
//
// The sibling guards in this family are NOT affected and keep their own canonical blocks:
// #706 (title/title_accent into pp_render_heading_with_accent) is still here below, and
// #641 (image_url into pp_render_responsive_image) still lives in components/logos.



// ── v2: the band id, and the one attribute this template emits for it ────────
//
// stats declares ROLES, not style slots, so there is no `__pp_style` map to render and no
// `style` attribute at all. The engine compiles the band's `udc` map into a band-scoped
// block in the document head; this template's whole contribution is saying WHICH band.
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
<section<?php echo $id ? ' id="' . esc_attr($id) . '"' : ''; ?> class="stats" data-pp-component="stats"<?php echo $band_attr; ?><?php echo $overlay_attr; ?>>
    <div class="container">

        <?php if ($title) : ?>
            <h2 class="stats__heading"><?php echo pp_render_heading_with_accent($title, $title_accent, 'stats__heading-accent'); ?></h2>
        <?php endif; ?>

        <?php if (!empty($items)) : ?>
            <ul class="stats__list" role="list">
                <?php foreach ($items as $item) :
                    $number = $item['number'] ?? '';
                    $label  = $item['label']  ?? '';
                ?>
                    <li class="stats__item">
                        <?php if ($number) : ?>
                            <span class="stats__number"><?php echo esc_html($number); ?></span>
                        <?php endif; ?>
                        <?php if ($label) : ?>
                            <span class="stats__label"><?php echo esc_html($label); ?></span>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

    </div>
</section>
