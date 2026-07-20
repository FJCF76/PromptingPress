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
$title            = $props['title']            ?? '';
$title_accent     = $props['title_accent']     ?? '';
$theme            = $props['theme']            ?? 'default';
$items            = $props['items']            ?? [];
$background_image = $props['background_image'] ?? '';

// theme coercion + the deprecated 'dark' -> 'muted' alias live in pp_theme_class() (#442).
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
