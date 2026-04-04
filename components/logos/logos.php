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
$variant = $props['variant'] ?? 'default';
$items   = $props['items']   ?? [];

$allowed_variants = ['default', 'dark', 'inverted'];
if (!in_array($variant, $allowed_variants, true)) {
    $variant = 'default';
}

$variant_class = $variant !== 'default' ? ' logos--' . $variant : '';
?>
<section<?php echo $id ? ' id="' . esc_attr($id) . '"' : ''; ?> class="logos<?php echo esc_attr($variant_class); ?>">
    <div class="container">

        <?php if ($title) : ?>
            <h2 class="logos__heading"><?php echo esc_html($title); ?></h2>
        <?php endif; ?>

        <?php if (!empty($items)) : ?>
            <ul class="logos__list" role="list">
                <?php foreach ($items as $item) :
                    $image_url = $item['image_url'] ?? '';
                    $image_alt = $item['image_alt'] ?? '';
                    $label     = $item['label']     ?? '';
                ?>
                    <?php if ($image_url) : ?>
                        <li class="logos__item<?php echo $label ? ' logos__item--labeled' : ''; ?>">
                            <img
                                src="<?php echo esc_url($image_url); ?>"
                                alt="<?php echo esc_attr($image_alt); ?>"
                                class="logos__image"
                                loading="lazy"
                            >
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
