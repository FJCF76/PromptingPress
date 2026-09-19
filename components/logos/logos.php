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
$items   = $props['items']   ?? [];

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
// nothing. Pinned behaviourally in TableEmbedLogosMarkupTest.
$raw_band  = $props['__pp_udc_band'] ?? '';
$band_id   = (is_scalar($raw_band) && pp_udc_valid_band_id((string) $raw_band)) ? (string) $raw_band : '';
$band_attr = $band_id !== '' ? ' data-pp-band="' . esc_attr($band_id) . '"' : '';

// The engine decides whether a scrim is being painted; the template just consumes the
// flag. It is what switches the focus ring to the on-overlay accent, where the ordinary
// `--color-accent` measures 1.17:1 over a dark scrim (#986's mechanism, #1035's defect).
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
                    // #708 (the `__pp_style` map into pp_render_style_vars, and grid's
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
