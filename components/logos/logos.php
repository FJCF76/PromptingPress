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
$theme = $props['theme'] ?? 'default';
$items   = $props['items']   ?? [];

// theme coercion lives in pp_theme_class(); `muted` emits the legacy `--dark` class (#570 DG-4).
$theme_class = pp_theme_class($theme, 'logos');

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
$slot_style = pp_render_style_vars($style, 'logos');
$style_attr = $slot_style ? ' style="' . $slot_style . ';"' : '';
?>
<section<?php echo $id ? ' id="' . esc_attr($id) . '"' : ''; ?> class="logos<?php echo esc_attr($theme_class); ?>" data-pp-component="logos"<?php echo $style_attr; ?>>
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
                    // components/cta/cta.php and components/hero/hero.php respectively;
                    // #708 (the `__pp_style` map into pp_render_style_vars, and grid's
                    // count($items)) has since LANDED too, and this file carries its
                    // guard at the top; its canonical block is in
                    // components/grid/grid.php. Still open on this corridor:
                    // #730, #733, #736, #738, #739 and #740.
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
