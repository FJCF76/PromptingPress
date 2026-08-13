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

$slot_style = pp_render_style_vars($props['__pp_style'] ?? [], 'logos');
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
                    $image_url = $item['image_url'] ?? '';
                    $image_alt = $item['image_alt'] ?? '';
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
