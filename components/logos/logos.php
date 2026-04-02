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

$title = $props['title'] ?? '';
$items = $props['items'] ?? [];
?>
<section class="logos">
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
