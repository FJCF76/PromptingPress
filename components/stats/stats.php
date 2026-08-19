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
$theme            = $props['theme']            ?? 'default';
$items            = $props['items']            ?? [];
// #705: guard the raw-value argument of pp_esc_image_src() (`string $url`) before it
// reaches the call below. A non-empty array is truthy, so the `if ($background_image)`
// gate passes on one and the typed call raises a TypeError that no caller catches —
// the whole PUBLIC PAGE 500s. Guarded at the READ because this prop drives three gates
// (the --has-bg-image modifier, the inline background-image, and the overlay div) and
// the read is upstream of all of them, so a guarded-away value renders the band exactly
// as an empty background_image already does. is_scalar + (string), NOT is_string: only
// non-scalars ever fataled (coercive mode), and the write path stores a scalar
// background_image raw (#707), so is_string() would silently drop an accepted value.
// Full reasoning in components/cta/cta.php. Reachable from STORED data even though the
// write path rejects the shape, because the validator gates WRITES, not storage: #233
// restore reports without blocking, a pre-rule composition still carries the value, and
// a raw _pp_composition meta write is not gated at all. (Stated directly rather than by
// comparison — stats has no image_url prop, so there is no local guard to point at.)
$raw_background_image = $props['background_image'] ?? '';
$background_image     = is_scalar($raw_background_image) ? (string) $raw_background_image : '';

// theme coercion lives in pp_theme_class(); `muted` emits the legacy `--dark` class (#570 DG-4).
$theme_class    = pp_theme_class($theme, 'stats');
$bg_image_class = $background_image ? ' stats--has-bg-image' : '';

// Style slot overrides (per-instance visual customization).
$slot_style = pp_render_style_vars($props['__pp_style'] ?? [], 'stats');

$inline_styles = [];
if ($slot_style) {
    $inline_styles[] = $slot_style;
}
if ($background_image) {
    $inline_styles[] = 'background-image:url(' . pp_esc_image_src($background_image) . ')';
}
$style_attr = $inline_styles ? ' style="' . implode('; ', $inline_styles) . ';"' : '';

?>
<section<?php echo $id ? ' id="' . esc_attr($id) . '"' : ''; ?> class="stats<?php echo esc_attr($theme_class); ?><?php echo esc_attr($bg_image_class); ?>" data-pp-component="stats"<?php echo $style_attr; ?>>
    <?php if ($background_image) : ?>
        <div class="stats__overlay" aria-hidden="true"></div>
    <?php endif; ?>
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
